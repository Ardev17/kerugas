<?php

use App\Enums\UserRole;
use App\Jobs\SendPushForNotificationJob;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use App\Services\GasOrderService;
use App\Services\Push\PushMessage;
use App\Services\Push\PushMessageFactory;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePushSender;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->driver = User::factory()->create([
        'role' => UserRole::DRIVER,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'driver_available' => true,
    ]);
    $this->customer = User::factory()->create();
    $this->order = GasOrder::factory()->create([
        'user_id' => $this->customer->id,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'neighborhood' => 'Bairro Comercial',
    ]);
});

it('cada notificação do app é enfileirada para push', function () {
    Queue::fake();

    $notification = AppNotification::create([
        'user_id' => $this->customer->id,
        'category' => 'system',
        'title' => 'Olá',
        'body' => 'Teste',
    ]);

    Queue::assertPushed(SendPushForNotificationJob::class, fn ($job) => $job->notificationId === $notification->id);
});

it('o condutor é notificado quando lhe atribuem um pedido, e o toque abre o painel dele', function () {
    Queue::fake();

    app(GasOrderService::class)->assign($this->order, $this->driver, $this->admin);

    $notification = AppNotification::where('user_id', $this->driver->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->title)->toBe('Novo pedido atribuído')
        ->and($notification->body)->toContain($this->order->order_reference)
        ->and($notification->body)->toContain('Bairro Comercial');

    $message = app(PushMessageFactory::class)->fromNotification($notification, $this->driver);
    expect($message->path)->toBe('/driver')
        ->and($message->channel)->toBe(PushMessage::CHANNEL_ORDERS);
});

it('o cliente com conta acompanha o pedido e o toque abre a página de acompanhamento', function () {
    Queue::fake();

    app(GasOrderService::class)->confirm($this->order, 2500, 'Botija 12kg', $this->admin);

    $notification = AppNotification::where('user_id', $this->customer->id)->first();
    expect($notification->title)->toBe('Pedido confirmado');

    $message = app(PushMessageFactory::class)->fromNotification($notification, $this->customer);
    expect($message->path)->toBe('/pedido/' . $this->order->order_reference);
});

it('o cliente não é notificado da ação que ele próprio fez', function () {
    Queue::fake();

    app(GasOrderService::class)->cancel($this->order, $this->customer);

    expect(AppNotification::where('user_id', $this->customer->id)->count())->toBe(0);
});

it('envia para todos os telemóveis do destinatário e apaga os que já não existem', function () {
    $fake = new FakePushSender(invalidTokens: ['uninstalled-phone']);
    $this->app->instance(PushSender::class, $fake);

    DeviceToken::create(['user_id' => $this->driver->id, 'token' => 'phone-1']);
    DeviceToken::create(['user_id' => $this->driver->id, 'token' => 'uninstalled-phone']);
    DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'someone-else']);

    Queue::fake();
    $notification = AppNotification::create([
        'user_id' => $this->driver->id,
        'category' => 'order',
        'title' => 'Novo pedido atribuído',
        'body' => 'Pedido X',
        'data' => ['reference' => 'KG-1'],
    ]);

    (new SendPushForNotificationJob($notification->id))->handle($fake, app(PushMessageFactory::class));

    expect($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['token'])->toBe('phone-1')
        ->and($fake->sent[0]['message']->path)->toBe('/driver')
        ->and(DeviceToken::where('token', 'uninstalled-phone')->exists())->toBeFalse()
        ->and(DeviceToken::where('token', 'someone-else')->exists())->toBeTrue();
});

it('um alerta de disponibilidade abre a página do posto', function () {
    $notification = new AppNotification([
        'user_id' => $this->customer->id,
        'category' => 'availability',
        'title' => 'Gás disponível',
        'body' => 'O posto X tem gás.',
        'data' => ['station_id' => 42],
    ]);

    $message = app(PushMessageFactory::class)->fromNotification($notification, $this->customer);

    expect($message->path)->toBe('/postos/42')
        ->and($message->channel)->toBe(PushMessage::CHANNEL_ALERTS);
});

it('iPhone vai pela Apple e Android pelo Firebase — cada um só com o seu fornecedor', function () {
    $fcm = new FakePushSender();
    $apns = new class extends \App\Services\Push\ApnsPushSender {
        /** @var array<int, string> */
        public array $sent = [];

        public function isEnabled(): bool
        {
            return true;
        }

        public function send(string $deviceToken, PushMessage $message): \App\Services\Push\PushResult
        {
            $this->sent[] = $deviceToken;

            return $deviceToken === 'apns-dead' ? \App\Services\Push\PushResult::INVALID_TOKEN : \App\Services\Push\PushResult::SENT;
        }
    };

    DeviceToken::create(['user_id' => $this->driver->id, 'token' => 'android-phone', 'platform' => 'android']);
    DeviceToken::create(['user_id' => $this->driver->id, 'token' => 'apns-live', 'platform' => 'ios']);
    DeviceToken::create(['user_id' => $this->driver->id, 'token' => 'apns-dead', 'platform' => 'ios']);

    Queue::fake();
    $notification = AppNotification::create([
        'user_id' => $this->driver->id, 'category' => 'order', 'title' => 'Novo pedido atribuído', 'body' => 'Pedido X',
    ]);

    (new SendPushForNotificationJob($notification->id))->handle($fcm, app(PushMessageFactory::class), null, $apns);

    expect(array_column($fcm->sent, 'token'))->toBe(['android-phone'])
        ->and($apns->sent)->toBe(['apns-live', 'apns-dead'])
        ->and(DeviceToken::where('token', 'apns-dead')->exists())->toBeFalse()
        ->and(DeviceToken::where('token', 'apns-live')->exists())->toBeTrue();
});

it('com a Apple desligada, o iPhone é ignorado sem apagar o token', function () {
    $fcm = new FakePushSender();
    DeviceToken::create(['user_id' => $this->driver->id, 'token' => 'apns-live', 'platform' => 'ios']);

    Queue::fake();
    $notification = AppNotification::create([
        'user_id' => $this->driver->id, 'category' => 'order', 'title' => 'T', 'body' => 'B',
    ]);

    (new SendPushForNotificationJob($notification->id))->handle($fcm, app(PushMessageFactory::class));

    expect($fcm->sent)->toBe([])
        ->and(DeviceToken::where('token', 'apns-live')->exists())->toBeTrue();
});
