<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\OrderPushSubscription;
use App\Models\Province;
use App\Models\User;
use App\Services\GasOrderService;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeWebPushSender;

uses(RefreshDatabase::class);

const ORDER_PUSH_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

beforeEach(function () {
    config(['queue.default' => 'sync']);

    $this->province = Province::factory()->create();
    $this->otherProvince = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);

    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->otherAdmin = User::factory()->admin()->create(['province_id' => $this->otherProvince->id]);
    $this->adminNoProvince = User::factory()->admin()->create(['province_id' => null]);
    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->driver = User::factory()->create([
        'role' => UserRole::DRIVER,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'driver_available' => true,
    ]);

    $this->fakePush = new FakeWebPushSender();
    $this->app->instance(WebPushSender::class, $this->fakePush);

    $this->order = GasOrder::factory()->create([
        'user_id' => null,
        'customer_phone' => '+244923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $this->payload = [
        'customer_phone' => '923456789',
        'endpoint' => ORDER_PUSH_ENDPOINT,
        'keys' => ['p256dh' => 'chave-publica', 'auth' => 'segredo'],
    ];
});

// ---------------------------------------------------------------- subscrição

it('cliente sem conta subscreve os avisos do pedido com referência + telefone', function () {
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertCreated();
    // Repetir não duplica.
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertCreated();

    expect(OrderPushSubscription::count())->toBe(1)
        ->and(OrderPushSubscription::first()->gas_order_id)->toBe($this->order->id);
});

it('telefone errado ou referência inexistente respondem o mesmo 404 e não guardam nada', function () {
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", [...$this->payload, 'customer_phone' => '911111111'])
        ->assertNotFound();
    $this->postJson('/api/v1/orders/reference/KG-00000000-000000/push', $this->payload)->assertNotFound();

    expect(OrderPushSubscription::count())->toBe(0);
});

it('só aceita endereços de serviços de push reais por HTTPS (SSRF)', function () {
    foreach (['http://atacante.local/push', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com.atacante.com/x'] as $endpoint) {
        $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", [...$this->payload, 'endpoint' => $endpoint])
            ->assertStatus(422)->assertJsonValidationErrors(['endpoint']);
    }

    expect(OrderPushSubscription::count())->toBe(0);
});

it('um pedido já terminado não aceita subscrições', function () {
    $this->order->update(['status' => GasOrderStatus::DELIVERED]);

    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertStatus(409);
});

it('sem Web Push ativo no servidor responde 503 em vez de fingir que subscreveu', function () {
    $this->app->instance(WebPushSender::class, new class extends WebPushSender {
        public function isEnabled(): bool
        {
            return false;
        }
    });

    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertStatus(503);
    expect(OrderPushSubscription::count())->toBe(0);
});

it('cancelar a subscrição também exige o telefone do pedido', function () {
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertCreated();

    $this->deleteJson("/api/v1/orders/reference/{$this->order->order_reference}/push", ['customer_phone' => '911111111', 'endpoint' => ORDER_PUSH_ENDPOINT])
        ->assertNotFound();
    expect(OrderPushSubscription::count())->toBe(1);

    $this->deleteJson("/api/v1/orders/reference/{$this->order->order_reference}/push", ['customer_phone' => '923456789', 'endpoint' => ORDER_PUSH_ENDPOINT])
        ->assertOk();
    expect(OrderPushSubscription::count())->toBe(0);
});

// ------------------------------------------------- avisos ao cliente sem conta

it('cliente sem conta recebe push em cada passo e o toque abre o acompanhamento', function () {
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertCreated();
    $service = app(GasOrderService::class);

    $service->markContacted($this->order, $this->admin);
    $service->confirm($this->order->fresh(), 2500, 'Botija 12kg', $this->admin);

    expect(collect($this->fakePush->sent)->pluck('message.title')->all())
        ->toBe(['Estamos a contactá-lo', 'Pedido confirmado']);

    $last = $this->fakePush->sent[1];
    expect($last['endpoint'])->toBe(ORDER_PUSH_ENDPOINT)
        ->and($last['message']->path)->toBe('/pedido/' . $this->order->order_reference)
        ->and($last['message']->channel)->toBe('orders');
});

it('no estado final o cliente recebe o último aviso e a subscrição é apagada', function () {
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertCreated();

    app(GasOrderService::class)->cancel($this->order, $this->admin);

    expect($this->fakePush->sent)->toHaveCount(1)
        ->and($this->fakePush->sent[0]['message']->title)->toBe('Pedido cancelado')
        ->and(OrderPushSubscription::count())->toBe(0);
});

it('subscrições expiradas são apagadas e não impedem a mudança de estado', function () {
    $this->fakePush = new FakeWebPushSender([ORDER_PUSH_ENDPOINT]);
    $this->app->instance(WebPushSender::class, $this->fakePush);
    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/push", $this->payload)->assertCreated();

    $order = app(GasOrderService::class)->markContacted($this->order, $this->admin);

    expect($order->status)->toBe(GasOrderStatus::CONTACTING)
        ->and(OrderPushSubscription::count())->toBe(0);
});

it('só o pedido certo é avisado — subscrições de outro pedido não recebem nada', function () {
    $other = GasOrder::factory()->create(['user_id' => null, 'customer_phone' => '+244911111111', 'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id]);
    $this->postJson("/api/v1/orders/reference/{$other->order_reference}/push", [...$this->payload, 'customer_phone' => '911111111'])->assertCreated();

    app(GasOrderService::class)->markContacted($this->order, $this->admin);

    expect($this->fakePush->sent)->toBe([]);
});

// ------------------------------------------------------------- avisos à equipa

it('um novo pedido avisa só os Admins da província do pedido e os Super Admins', function () {
    app(GasOrderService::class)->create([
        'customer_name' => 'Maria',
        'customer_phone' => '+244923000111',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'quantity' => 1,
    ]);

    expect(AppNotification::where('user_id', $this->admin->id)->count())->toBe(1)
        ->and(AppNotification::where('user_id', $this->super->id)->count())->toBe(1)
        ->and(AppNotification::where('user_id', $this->otherAdmin->id)->count())->toBe(0)
        ->and(AppNotification::where('user_id', $this->adminNoProvince->id)->count())->toBe(0);
});

it('os Admins são avisados do pedido novo mesmo com o tempo real (Reverb) em baixo', function () {
    // Simula o Reverb desligado: a transmissão do pedido rebenta.
    \Illuminate\Support\Facades\Event::listen(\App\Events\GasOrderCreatedBroadcast::class, function () {
        throw new RuntimeException('Reverb offline');
    });

    $order = app(GasOrderService::class)->create([
        'customer_name' => 'Maria',
        'customer_phone' => '+244923000222',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'quantity' => 1,
    ]);

    expect($order->exists)->toBeTrue()
        ->and(AppNotification::where('user_id', $this->admin->id)->where('title', 'Novo pedido de gás')->count())->toBe(1);
});

it('Admin suspenso não recebe avisos de pedidos', function () {
    $this->admin->update(['status' => 'SUSPENDED']);

    app(GasOrderService::class)->create([
        'customer_name' => 'Maria',
        'customer_phone' => '+244923000111',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'quantity' => 1,
    ]);

    expect(AppNotification::where('user_id', $this->admin->id)->count())->toBe(0);
});

it('o condutor que cancela a entrega avisa os Admins da província, não os de outra', function () {
    $service = app(GasOrderService::class);
    $service->assign($this->order, $this->driver, $this->admin);

    $service->cancel($this->order->fresh(), $this->driver, 'Sem combustível');

    $titles = fn (User $u) => AppNotification::where('user_id', $u->id)->pluck('title')->all();
    expect($titles($this->admin))->toContain('Condutor cancelou a entrega')
        ->and($titles($this->super))->toContain('Condutor cancelou a entrega')
        ->and($titles($this->otherAdmin))->toBe([])
        // O próprio condutor não é avisado da ação que acabou de fazer.
        ->and($titles($this->driver))->not->toContain('Pedido cancelado');
});

it('o Admin que cancela um pedido atribuído avisa o condutor', function () {
    $service = app(GasOrderService::class);
    $service->assign($this->order, $this->driver, $this->admin);

    $service->cancel($this->order->fresh(), $this->admin);

    expect(AppNotification::where('user_id', $this->driver->id)->pluck('title')->all())
        ->toContain('Pedido cancelado');
    // E o Admin, que fez a ação, não recebe aviso dela.
    expect(AppNotification::where('user_id', $this->admin->id)->pluck('title')->all())
        ->not->toContain('Cliente cancelou o pedido');
});

it('o cliente com conta que cancela avisa a equipa mas não a si próprio', function () {
    $customer = User::factory()->create();
    $order = GasOrder::factory()->create(['user_id' => $customer->id, 'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id]);

    app(GasOrderService::class)->cancel($order, $customer);

    expect(AppNotification::where('user_id', $customer->id)->count())->toBe(0)
        ->and(AppNotification::where('user_id', $this->admin->id)->pluck('title')->all())->toContain('Cliente cancelou o pedido')
        ->and(AppNotification::where('user_id', $this->otherAdmin->id)->count())->toBe(0);
});
