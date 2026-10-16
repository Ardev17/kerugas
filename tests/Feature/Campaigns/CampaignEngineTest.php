<?php

use App\Enums\UserRole;
use App\Jobs\SendCampaignSmsJob;
use App\Jobs\SendPushForNotificationJob;
use App\Models\AppNotification;
use App\Models\CampaignDelivery;
use App\Models\GasOrder;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use App\Services\GasOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Motor de campanhas (Setembro 2026): as regras que protegem as pessoas
 * de notificações a mais, fora de horas, ou que não pediram.
 */
beforeEach(function () {
    Queue::fake();
    // 10:00 em Luanda — fora das horas de silêncio.
    $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Africa/Luanda')->utc());
    $this->service = app(CampaignService::class);
    $this->customer = User::factory()->create(['name' => 'Maria Luísa Kiala', 'role' => UserRole::USER]);
});

it('entrega no sino com o primeiro nome, o ecrã certo, e envia push', function () {
    $delivery = $this->service->deliver('gas_reminder', $this->customer, ['dias' => 18], 'k1');

    expect($delivery)->not->toBeNull();
    $notification = AppNotification::find($delivery->app_notification_id);
    expect($notification->title . ' ' . $notification->body)->toContain('Maria')
        ->and($notification->title)->not->toContain('Kiala')
        ->and($notification->data['path'])->toBe('/pedir-gas?repetir=1')
        ->and($notification->data['channel'])->toBe('reminders');

    Queue::assertPushed(SendPushForNotificationJob::class);
});

it('o lembrete de gás também sai por SMS, sem links e num só SMS', function () {
    $this->service->deliver('gas_reminder', $this->customer, ['dias' => 18], 'k1');

    Queue::assertPushed(SendCampaignSmsJob::class, function (SendCampaignSmsJob $job) {
        $text = app(\App\Services\Sms\SmsTextNormalizer::class)->toGsm7($job->text);
        $count = app(\App\Services\Sms\SmsSegmentCounter::class)->count($text);

        return !preg_match('/https?:|www\.|\.com|\.ao\b/i', $text)
            && $count['segments'] === 1
            && $count['encoding'] === 'GSM-7';
    });
});

it('quem desligou o SMS nas preferências recebe só no app', function () {
    $this->customer->update(['notification_preferences' => ['sms' => false]]);

    expect($this->service->deliver('gas_reminder', $this->customer, ['dias' => 18], 'k1'))->not->toBeNull();
    Queue::assertNotPushed(SendCampaignSmsJob::class);
});

it('respeita as preferências: lembretes desligados = nada', function () {
    $this->customer->update(['notification_preferences' => ['reminders' => false]]);

    expect($this->service->deliver('gas_reminder', $this->customer, [], 'k1'))->toBeNull()
        ->and(AppNotification::count())->toBe(0);
});

it('só chega ao papel certo: um condutor não recebe o lembrete de cliente', function () {
    $driver = User::factory()->create(['role' => UserRole::DRIVER]);

    expect($this->service->deliver('gas_reminder', $driver, [], 'k1'))->toBeNull();
});

it('nada sai nas horas de silêncio (22:00), mas as boas-vindas a um registo feito à noite sim', function () {
    $this->travelTo(Carbon::parse('2026-10-01 22:00', 'Africa/Luanda')->utc());

    expect($this->service->deliver('gas_reminder', $this->customer, [], 'k1'))->toBeNull()
        ->and($this->service->deliver('welcome', $this->customer, [], 'w1', ['ignore_quiet_hours' => true]))->not->toBeNull();
});

it('no máximo 2 promoções por semana por pessoa', function () {
    expect($this->service->deliver('weekend_eve', $this->customer, [], 'p1'))->not->toBeNull()
        ->and($this->service->deliver('account_anniversary', $this->customer, ['anos' => '1 ano'], 'p2'))->not->toBeNull()
        ->and($this->service->deliver('we_miss_you', $this->customer, [], 'p3'))->toBeNull();

    // Uma semana depois, volta a poder.
    $this->travel(8)->days();
    expect($this->service->deliver('we_miss_you', $this->customer, [], 'p4'))->not->toBeNull();
});

it('nunca repete o mesmo envio (mesma chave)', function () {
    $this->service->deliver('gas_reminder', $this->customer, [], 'mesma-chave');
    $this->service->deliver('gas_reminder', $this->customer, [], 'mesma-chave');

    expect(CampaignDelivery::count())->toBe(1);
});

it('campanha desligada pelo Super Admin não envia; o teste do Super Admin (force) sim', function () {
    NotificationCampaign::create(['key' => 'gas_reminder', 'enabled' => false]);

    expect($this->service->deliver('gas_reminder', $this->customer, [], 'k1'))->toBeNull()
        ->and($this->service->deliver('gas_reminder', $this->customer, [], null, ['force' => true]))->not->toBeNull();
});

it('textos editados pelo Super Admin substituem os originais', function () {
    NotificationCampaign::create(['key' => 'weekend_eve', 'templates' => [['title' => 'Olá {nome}, texto novo', 'body' => 'Corpo novo']]]);

    $delivery = $this->service->deliver('weekend_eve', $this->customer, [], 'k1');

    expect(AppNotification::find($delivery->app_notification_id)->title)->toBe('Olá Maria, texto novo');
});

it('um pedido até 48h depois conta como conversão da notificação', function () {
    $delivery = $this->service->deliver('gas_reminder', $this->customer, [], 'k1');
    $this->travel(5)->hours();

    $order = app(GasOrderService::class)->create(GasOrder::factory()->raw(['user_id' => $this->customer->id]));

    expect($delivery->fresh()->converted_order_id)->toBe($order->id);
});
