<?php

use App\Enums\GasOrderStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Subscrição semanal do posto e pedidos do município (Novembro 2026).
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->catumbela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Catumbela']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);

    $this->postoA = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'name' => 'Posto A']);
    $this->postoB = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'name' => 'Posto B']);
    $this->postoC = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->catumbela->id, 'name' => 'Posto Catumbela']);

    $this->global = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->psa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->psaHuila = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->huila->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->benguela->id]);

    $this->gerenteA = User::factory()->create(['role' => UserRole::STATION_MANAGER, 'managed_station_id' => $this->postoA->id, 'municipality_id' => $this->lobito->id]);
    $this->operadorA = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->postoA->id, 'municipality_id' => $this->lobito->id]);
    $this->gerenteB = User::factory()->create(['role' => UserRole::STATION_MANAGER, 'managed_station_id' => $this->postoB->id, 'municipality_id' => $this->lobito->id]);
    $this->condutorA = User::factory()->create(['role' => UserRole::STATION_DRIVER, 'driver_station_id' => $this->postoA->id, 'municipality_id' => $this->lobito->id, 'driver_available' => true]);
    $this->condutorB = User::factory()->create(['role' => UserRole::STATION_DRIVER, 'driver_station_id' => $this->postoB->id, 'municipality_id' => $this->lobito->id]);
});

function subscribe(Station $s, int $weeks = 1): Subscription
{
    return Subscription::create([
        'type' => SubscriptionType::STATION_ORDERS, 'station_id' => $s->id, 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subMinute(), 'expires_at' => now()->addWeeks($weeks), 'months' => 0, 'weeks' => $weeks,
    ]);
}

function lobitoOrder(array $extra = []): GasOrder
{
    $t = test();

    return GasOrder::factory()->create(array_merge([
        'province_id' => $t->benguela->id, 'municipality_id' => $t->lobito->id,
        'status' => GasOrderStatus::PENDING, 'assigned_to' => null, 'station_id' => null,
        'customer_name' => 'Cliente Lobito', 'customer_phone' => '+244923777000',
    ], $extra));
}

// ───────────────────────── subscrição

it('o Super Admin ativa a subscrição em blocos de semanas, a 1000 Kz por semana', function () {
    Sanctum::actingAs($this->global);

    $r = $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id, 'weeks' => 2])
        ->assertCreated();

    expect($r->json('data.weeks'))->toBe(2)
        ->and($r->json('data.amount_kz'))->toBe(2000)
        ->and(AppNotification::where('user_id', $this->gerenteA->id)->where('title', 'Pedidos do município ativos')->exists())->toBeTrue()
        ->and(AppNotification::where('user_id', $this->operadorA->id)->exists())->toBeTrue();

    // Renovar: o novo bloco começa quando o anterior acaba.
    $r2 = $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id, 'weeks' => 4])->assertCreated();
    expect($r2->json('data.starts_at'))->toBe($r->json('data.expires_at'));
});

it('o preço semanal só é alterado pelo Super Admin geral', function () {
    Sanctum::actingAs($this->global);
    $this->putJson('/api/v1/admin/subscriptions/settings', ['price_station_orders_week_kz' => 1500])->assertOk();
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id, 'weeks' => 2])
        ->assertCreated()->assertJsonPath('data.amount_kz', 3000);

    Sanctum::actingAs($this->psa);
    $this->putJson('/api/v1/admin/subscriptions/settings', ['price_station_orders_week_kz' => 1])->assertForbidden();
});

it('o Provincial ativa só para postos da sua província e o Admin comum nunca', function () {
    Sanctum::actingAs($this->psa);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id, 'weeks' => 1])->assertCreated();

    Sanctum::actingAs($this->psaHuila);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id, 'weeks' => 1])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id, 'weeks' => 1])->assertForbidden();
});

it('as semanas são obrigatórias', function () {
    Sanctum::actingAs($this->global);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ORDERS', 'station_id' => $this->postoA->id])
        ->assertStatus(422)->assertJsonValidationErrors('weeks');
});

// ───────────────────────── ver e aceitar pedidos

it('sem subscrição o posto não vê nem aceita pedidos do município', function () {
    $order = lobitoOrder();
    Sanctum::actingAs($this->gerenteA);

    $r = $this->getJson('/api/v1/station/orders')->assertOk();
    expect($r->json('data'))->toBe([])->and($r->json('meta.subscription.active'))->toBeFalse()
        ->and($r->json('meta.subscription.price_per_week_kz'))->toBe(1000);

    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertForbidden();
});

it('com a subscrição ativa vê os pedidos abertos do seu município, com os dados do cliente', function () {
    subscribe($this->postoA);
    $aberto = lobitoOrder();
    lobitoOrder(['municipality_id' => $this->catumbela->id]);
    lobitoOrder(['status' => GasOrderStatus::CONFIRMED]);
    lobitoOrder(['station_id' => $this->postoB->id, 'status' => GasOrderStatus::CONFIRMED]);

    Sanctum::actingAs($this->operadorA);
    $r = $this->getJson('/api/v1/station/orders')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.id'))->toBe($aberto->id)
        ->and($r->json('data.0.customer_phone'))->toBe('+244923777000')
        ->and($r->json('meta.subscription.active'))->toBeTrue();
});

it('quem aceita primeiro fica com o pedido, sem comissão KeruGás', function () {
    subscribe($this->postoA);
    subscribe($this->postoB);
    $order = lobitoOrder(['quantity' => 2]);

    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 18000])
        ->assertOk()->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.accepted_station.name', 'Posto A');

    Sanctum::actingAs($this->gerenteB);
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 15000])->assertStatus(409);

    $o = $order->fresh();
    expect($o->station_id)->toBe($this->postoA->id)
        ->and((float) $o->delivery_price)->toBe(18000.0)
        ->and((float) $o->commission)->toBe(0.0)
        ->and((float) $o->bottle_price)->toBe(18000.0);
});

it('um posto não aceita pedidos de outro município nem pedidos já da KeruGás', function () {
    subscribe($this->postoC);
    subscribe($this->postoA);
    $order = lobitoOrder();
    $keru = lobitoOrder(['status' => GasOrderStatus::CONFIRMED]);

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::STATION_MANAGER, 'managed_station_id' => $this->postoC->id]));
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertForbidden();

    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$keru->id}/accept", ['delivery_price' => 9000])->assertStatus(409);
});

it('a subscrição desliga-se sozinha no fim do prazo', function () {
    $sub = subscribe($this->postoA);
    $order = lobitoOrder();
    $sub->update(['expires_at' => now()->subMinute()]);

    Sanctum::actingAs($this->gerenteA);
    expect($this->getJson('/api/v1/station/orders')->json('meta.subscription.active'))->toBeFalse();
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertForbidden();
});

it('os postos subscritos do município são avisados de um pedido novo', function () {
    subscribe($this->postoA);
    $this->postJson('/api/v1/orders', [
        'customer_name' => 'Maria', 'customer_phone' => '923111333',
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
        'address' => 'Rua da Praia', 'quantity' => 1, 'location_source' => 'MANUAL',
    ])->assertCreated();

    expect(AppNotification::where('user_id', $this->gerenteA->id)->where('title', 'Novo pedido no seu município')->exists())->toBeTrue()
        ->and(AppNotification::where('user_id', $this->operadorA->id)->where('title', 'Novo pedido no seu município')->exists())->toBeTrue()
        ->and(AppNotification::where('user_id', $this->gerenteB->id)->where('title', 'Novo pedido no seu município')->exists())->toBeFalse();
});

// ───────────────────────── condutores do posto

it('o posto atribui só aos seus condutores e o condutor de posto entrega', function () {
    subscribe($this->postoA);
    $order = lobitoOrder();
    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertOk();

    $this->postJson("/api/v1/station/orders/{$order->id}/assign", ['driver_id' => $this->condutorB->id])->assertStatus(422);
    $keruDriver = User::factory()->create(['role' => UserRole::DRIVER, 'municipality_id' => $this->lobito->id]);
    $this->postJson("/api/v1/station/orders/{$order->id}/assign", ['driver_id' => $keruDriver->id])->assertStatus(422);

    $this->postJson("/api/v1/station/orders/{$order->id}/assign", ['driver_id' => $this->condutorA->id])
        ->assertOk()->assertJsonPath('data.status', 'ASSIGNED');
    expect((bool) $this->condutorA->fresh()->driver_available)->toBeFalse();

    Sanctum::actingAs($this->condutorA);
    expect($this->getJson('/api/v1/driver/orders')->json('data.0.id'))->toBe($order->id);
    $this->postJson("/api/v1/driver/orders/{$order->id}/complete")->assertOk();

    expect($order->fresh()->status)->toBe(GasOrderStatus::DELIVERED)
        ->and((bool) $this->condutorA->fresh()->driver_available)->toBeTrue();
});

it('o condutor de posto não tem carregamentos nem stock', function () {
    Sanctum::actingAs($this->condutorA);

    $this->getJson('/api/v1/driver/stock')->assertForbidden();
    $this->postJson('/api/v1/driver/pickups', [])->assertForbidden();
});

it('outro posto não mexe num pedido que não é seu', function () {
    subscribe($this->postoA);
    $order = lobitoOrder();
    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertOk();

    Sanctum::actingAs($this->gerenteB);
    $this->postJson("/api/v1/station/orders/{$order->id}/assign", ['driver_id' => $this->condutorB->id])->assertNotFound();
    $this->postJson("/api/v1/station/orders/{$order->id}/release")->assertNotFound();
});

it('o posto pode devolver o pedido à KeruGás', function () {
    subscribe($this->postoA);
    $order = lobitoOrder();
    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertOk();
    $this->postJson("/api/v1/station/orders/{$order->id}/assign", ['driver_id' => $this->condutorA->id])->assertOk();

    $this->postJson("/api/v1/station/orders/{$order->id}/release", ['reason' => 'Sem garrafas'])->assertOk();

    $o = $order->fresh();
    expect($o->station_id)->toBeNull()->and($o->assigned_to)->toBeNull()
        ->and($o->status)->toBe(GasOrderStatus::RECEIVED)->and($o->delivery_price)->toBeNull()
        ->and((bool) $this->condutorA->fresh()->driver_available)->toBeTrue();
});

// ───────────────────────── admins

it('o Admin comum não trata pedidos de posto; o Super Admin retira-os e reatribui', function () {
    subscribe($this->postoA);
    $order = lobitoOrder();
    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$order->id}/accept", ['delivery_price' => 9000])->assertOk();

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/orders/{$order->id}/contact")->assertStatus(422);
    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel")->assertForbidden();
    $this->postJson("/api/v1/admin/orders/{$order->id}/withdraw-station")->assertForbidden();

    Sanctum::actingAs($this->psaHuila);
    $this->postJson("/api/v1/admin/orders/{$order->id}/withdraw-station")->assertForbidden();

    Sanctum::actingAs($this->psa);
    $this->postJson("/api/v1/admin/orders/{$order->id}/withdraw-station", ['reason' => 'Cliente reclamou'])->assertOk();

    expect($order->fresh()->station_id)->toBeNull()
        ->and(AppNotification::where('user_id', $this->gerenteA->id)->where('title', 'Pedido retirado pela KeruGás')->exists())->toBeTrue();

    // Depois de retirado, segue o fluxo normal da KeruGás.
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/orders/{$order->id}/contact")->assertOk();
});

it('os pedidos entregues por postos não entram na faturação da KeruGás', function () {
    lobitoOrder(['status' => GasOrderStatus::DELIVERED, 'station_id' => $this->postoA->id, 'delivery_price' => 9000, 'bottle_price' => 9000, 'commission' => 0, 'completed_at' => now(), 'price_set_by' => $this->gerenteA->id]);
    lobitoOrder(['status' => GasOrderStatus::DELIVERED, 'delivery_price' => 10000, 'bottle_price' => 9500, 'commission' => 500, 'completed_at' => now(), 'price_set_by' => $this->admin->id]);

    $keruOnly = GasOrder::query()->keruGasOnly()->where('status', GasOrderStatus::DELIVERED)->sum('delivery_price');
    expect((float) $keruOnly)->toBe(10000.0);
});

// ───────────────── interruptor do administrador (Novembro 2026)

function mandarInterruptor(Station $s, string $estado, ?string $motivo = null): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/admin/stations/{$s->id}/orders-override",
        array_filter(['override' => $estado, 'reason' => $motivo]));
}

it('por omissão o interruptor não mexe em nada: manda a subscrição', function () {
    expect($this->postoA->fresh()->orders_override->value)->toBe('INHERIT');

    Sanctum::actingAs($this->gerenteA);
    $this->getJson('/api/v1/station/orders?scope=open')->assertOk()->assertJsonPath('meta.subscription.can_accept', false);

    subscribe($this->postoA);
    $this->getJson('/api/v1/station/orders?scope=open')->assertOk()->assertJsonPath('meta.subscription.can_accept', true);
});

it('ALLOW deixa o posto receber pedidos sem nunca ter pago', function () {
    Sanctum::actingAs($this->global);
    mandarInterruptor($this->postoA, 'ALLOW', 'Cortesia de lançamento')
        ->assertOk()
        ->assertJsonPath('data.orders_override', 'ALLOW')
        ->assertJsonPath('meta.subscription_active', false)
        ->assertJsonPath('meta.can_receive_orders', true);

    $pedido = lobitoOrder();

    Sanctum::actingAs($this->gerenteA);
    $this->getJson('/api/v1/station/orders?scope=open')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/station/orders/{$pedido->id}/accept", ['delivery_price' => 9000])->assertOk();

    expect($pedido->fresh()->station_id)->toBe($this->postoA->id)
        ->and(AppNotification::where('user_id', $this->operadorA->id)->where('title', 'Pedidos activados')->exists())->toBeTrue();
});

it('BLOCK trava o posto mesmo com a subscrição paga e em dia', function () {
    subscribe($this->postoA, 4);
    $pedido = lobitoOrder();

    Sanctum::actingAs($this->global);
    mandarInterruptor($this->postoA, 'BLOCK', 'Reclamações de clientes')
        ->assertOk()
        ->assertJsonPath('meta.subscription_active', true)
        ->assertJsonPath('meta.can_receive_orders', false);

    Sanctum::actingAs($this->gerenteA);
    $this->getJson('/api/v1/station/orders?scope=open')->assertOk()->assertJsonCount(0, 'data');

    // e a recusa diz o motivo que o administrador escreveu, não "pague"
    $this->postJson("/api/v1/station/orders/{$pedido->id}/accept", ['delivery_price' => 9000])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Reclamações de clientes');

    expect(AppNotification::where('user_id', $this->operadorA->id)->where('title', 'Pedidos suspensos')->exists())->toBeTrue();
});

it('voltar a INHERIT limpa o motivo e devolve a decisão à subscrição', function () {
    Sanctum::actingAs($this->global);
    mandarInterruptor($this->postoA, 'ALLOW', 'Cortesia')->assertOk();
    mandarInterruptor($this->postoA, 'INHERIT')->assertOk()->assertJsonPath('data.orders_override_reason', null);

    expect($this->postoA->fresh()->orders_override_by_id)->toBeNull();

    $pedido = lobitoOrder();

    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/station/orders/{$pedido->id}/accept", ['delivery_price' => 9000])
        ->assertStatus(403)
        ->assertJsonPath('message', 'A subscrição de pedidos do posto não está ativa. Renove para aceitar pedidos do município.');
    $this->getJson('/api/v1/station/orders?scope=open')->assertOk()->assertJsonPath('meta.subscription.can_accept', false);
});

it('o Provincial só mexe nos postos da sua província; o Admin comum nunca mexe', function () {
    Sanctum::actingAs($this->psa);
    mandarInterruptor($this->postoA, 'ALLOW')->assertOk();

    Sanctum::actingAs($this->psaHuila);
    mandarInterruptor($this->postoA, 'BLOCK')->assertForbidden();

    Sanctum::actingAs($this->admin);
    mandarInterruptor($this->postoA, 'BLOCK')->assertForbidden();
});

it('cada mudança do interruptor fica na auditoria, com o antes e o depois', function () {
    Sanctum::actingAs($this->global);
    mandarInterruptor($this->postoA, 'BLOCK', 'Em obras')->assertOk();

    $registo = \App\Models\AuditLog::where('action', 'station.orders_override_changed')->latest('id')->first();

    expect($registo)->not->toBeNull()
        ->and($registo->old_values['orders_override'])->toBe('INHERIT')
        ->and($registo->new_values['orders_override'])->toBe('BLOCK')
        ->and($registo->new_values['reason'])->toBe('Em obras');
});

it('recusa um estado que não existe', function () {
    Sanctum::actingAs($this->global);
    mandarInterruptor($this->postoA, 'TALVEZ')->assertStatus(422)->assertJsonValidationErrors('override');
});
