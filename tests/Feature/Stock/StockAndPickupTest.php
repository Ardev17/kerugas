<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationCounterReset;
use App\Models\User;
use App\Services\GasOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Carregamentos nos postos + stock do condutor + contador do posto
 * (Outubro 2026). O pedido sai do stock da KeruGás que o condutor leva;
 * o posto só aparece no carregamento.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->province->id]);
    $this->operator = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    $this->station = assignOperator($this->operator, Station::factory()->create(['name' => 'Posto Norte']));
    $this->driver = User::factory()->create([
        'role' => UserRole::DRIVER, 'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id, 'driver_available' => true,
    ]);
});

function pickupPayload(array $o = []): array
{
    return array_merge([
        'client_uuid' => (string) Str::uuid(),
        'station_id' => test()->station->id,
        'quantity' => 10,
        'amount' => 12000,
    ], $o);
}

function confirmedOrder(array $o = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'province_id' => test()->province->id, 'municipality_id' => test()->municipality->id,
        'status' => 'CONFIRMED', 'quantity' => 2, 'bottle_price' => 2400, 'commission' => 500, 'delivery_price' => 2900,
        'price_set_by' => test()->admin->id, 'price_set_at' => now(),
    ], $o));
}

// --- Registo do condutor ---

it('o condutor regista um carregamento, o stock sobe e o operador é avisado', function () {
    Sanctum::actingAs($this->driver);

    $r = $this->postJson('/api/v1/driver/pickups', pickupPayload())->assertCreated();

    expect($r->json('data.pickup.state'))->toBe('DRIVER_ONLY')
        ->and($r->json('data.stock.available'))->toBe(10);
    expect(AppNotification::where('user_id', $this->operator->id)->where('title', 'Confirme as garrafas levadas')->count())->toBe(1);

    $this->getJson('/api/v1/driver/stock')->assertOk()
        ->assertJsonPath('data.stock.on_hand', 10)
        ->assertJsonPath('data.pickups.0.station_name', 'Posto Norte');
});

it('reenviar o mesmo carregamento (fila offline) não duplica', function () {
    Sanctum::actingAs($this->driver);
    $payload = pickupPayload(['offline' => true, 'registered_at' => now()->subHours(3)->toIso8601String()]);

    $this->postJson('/api/v1/driver/pickups', $payload)->assertCreated();
    $this->postJson('/api/v1/driver/pickups', $payload)->assertOk();

    expect(BottlePickup::count())->toBe(1)
        ->and(BottlePickup::first()->synced_offline)->toBeTrue();
});

it('um carregamento com data futura fica com a hora do servidor', function () {
    Sanctum::actingAs($this->driver);

    $this->postJson('/api/v1/driver/pickups', pickupPayload(['registered_at' => now()->addDay()->toIso8601String()]))->assertCreated();

    expect(BottlePickup::first()->registered_at->lte(now()))->toBeTrue();
});

it('valida quantidade, valor e posto ativo', function () {
    Sanctum::actingAs($this->driver);

    $this->postJson('/api/v1/driver/pickups', pickupPayload(['quantity' => 0, 'amount' => -1]))
        ->assertStatus(422)->assertJsonValidationErrors(['quantity', 'amount']);

    $inactive = Station::factory()->create(['status' => 'INACTIVE']);
    $this->postJson('/api/v1/driver/pickups', pickupPayload(['station_id' => $inactive->id]))->assertStatus(422);
});

it('só condutores registam carregamentos', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/driver/pickups', pickupPayload())->assertStatus(403);
});

it('o condutor procura qualquer posto ativo', function () {
    Station::factory()->create(['name' => 'Posto Sul']);
    Sanctum::actingAs($this->driver);

    $names = collect($this->getJson('/api/v1/driver/stations?search=Sul')->assertOk()->json('data'))->pluck('name');
    expect($names->all())->toBe(['Posto Sul']);
});

// --- Confirmação do operador ---

it('o operador confirma; outro operador não pode', function () {
    $p = giveDriverStock($this->driver, 10, $this->station, 12000);
    $outro = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    assignOperator($outro, Station::factory()->create());

    Sanctum::actingAs($outro);
    $this->postJson("/api/v1/operator/pickups/{$p->id}/confirm")->assertStatus(403);

    Sanctum::actingAs($this->operator);
    $this->postJson("/api/v1/operator/pickups/{$p->id}/confirm")->assertOk()->assertJsonPath('data.state', 'CONFIRMED');
    $this->postJson("/api/v1/operator/pickups/{$p->id}/confirm")->assertStatus(422);
    expect(AppNotification::where('user_id', $this->driver->id)->where('title', 'Carregamento confirmado')->count())->toBe(1);
});

it('o operador contesta com nota e o Super Admin é avisado', function () {
    $p = giveDriverStock($this->driver, 10, $this->station);
    Sanctum::actingAs($this->operator);

    $this->postJson("/api/v1/operator/pickups/{$p->id}/dispute", ['note' => ''])->assertStatus(422);
    $this->postJson("/api/v1/operator/pickups/{$p->id}/dispute", ['note' => 'Levou só 8'])->assertOk()->assertJsonPath('data.state', 'DISPUTED');

    expect(AppNotification::where('user_id', $this->superAdmin->id)->where('title', 'Carregamento contestado')->count())->toBe(1);
});

it('o operador vê o contador e os carregamentos do seu posto', function () {
    giveDriverStock($this->driver, 10, $this->station);
    giveDriverStock($this->driver, 4, $this->station);
    Sanctum::actingAs($this->operator);

    $r = $this->getJson('/api/v1/operator/pickups')->assertOk();
    expect($r->json('data.counter.since_reset'))->toBe(14)
        ->and($r->json('data.counter.today'))->toBe(14)
        ->and($r->json('data.counter.pending_confirmation'))->toBe(2)
        ->and(count($r->json('data.pickups')))->toBe(2);
});

// --- Stock do condutor ---

it('sem garrafas suficientes não se envia o pedido ao condutor', function () {
    $order = confirmedOrder(['quantity' => 3]);
    giveDriverStock($this->driver, 2);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$order->id}/assign-driver", ['driver_id' => $this->driver->id])
        ->assertStatus(422)->assertJsonValidationErrors('stock');

    giveDriverStock($this->driver, 1);
    $this->postJson("/api/v1/admin/orders/{$order->id}/assign-driver", ['driver_id' => $this->driver->id])->assertOk();
});

it('garrafas de um pedido já atribuído ficam reservadas', function () {
    giveDriverStock($this->driver, 3);
    $a = confirmedOrder(['quantity' => 2]);
    app(GasOrderService::class)->assign($a, $this->driver, $this->admin);
    $this->driver->update(['driver_available' => true]);

    $b = confirmedOrder(['quantity' => 2]);
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/orders/{$b->id}/assign-driver", ['driver_id' => $this->driver->id])
        ->assertStatus(422)->assertJsonValidationErrors('stock');
});

it('confirmar a entrega desconta do stock; sem garrafas em mão é recusado', function () {
    $order = confirmedOrder(['quantity' => 2]);
    app(GasOrderService::class)->assign($order, $this->driver, $this->admin);
    Sanctum::actingAs($this->driver);

    $this->postJson("/api/v1/driver/orders/{$order->id}/complete")->assertStatus(422)->assertJsonValidationErrors('stock');
    expect($order->fresh()->status->value)->toBe('ASSIGNED');

    giveDriverStock($this->driver, 5);
    $this->postJson("/api/v1/driver/orders/{$order->id}/complete")->assertOk();

    expect($order->fresh())->status->value->toBe('DELIVERED')->stock_driver_id->toBe($this->driver->id);
    $this->getJson('/api/v1/driver/stock')->assertJsonPath('data.stock.on_hand', 3)->assertJsonPath('data.stock.available', 3);
});

it('as garrafas que sobram ficam com o condutor para o dia seguinte', function () {
    giveDriverStock($this->driver, 5)->update(['registered_at' => now()->subDay()]);
    Sanctum::actingAs($this->driver);

    $this->getJson('/api/v1/driver/stock')->assertJsonPath('data.stock.available', 5);
});

it('o admin vê o stock de cada condutor na lista de condutores', function () {
    giveDriverStock($this->driver, 7);
    Sanctum::actingAs($this->admin);

    $row = collect($this->getJson('/api/v1/admin/drivers')->assertOk()->json('data'))->firstWhere('id', $this->driver->id);
    expect($row['bottle_stock']['available'])->toBe(7);
});

it('o Super Admin também passa pela regra do stock ao concluir', function () {
    $order = confirmedOrder(['quantity' => 2]);
    app(GasOrderService::class)->assign($order, $this->driver, $this->admin);
    Sanctum::actingAs($this->superAdmin);

    $this->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertStatus(422);
});

// --- Admin: valor do carregamento e contador ---

it('só o Super Admin (Geral ou Provincial) corrige o valor de um carregamento (auditado); pago não se corrige', function () {
    $p = giveDriverStock($this->driver, 10, $this->station, 12000);

    // Admin comum só vê (Outubro 2026).
    Sanctum::actingAs($this->admin);
    $this->patchJson("/api/v1/admin/pickups/{$p->id}", ['amount' => 13000])->assertForbidden();

    $provincial = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->province->id]);
    Sanctum::actingAs($provincial);
    $this->patchJson("/api/v1/admin/pickups/{$p->id}", ['amount' => 13000])->assertOk()->assertJsonPath('data.amount', 13000);
    expect(AuditLog::where('action', 'pickup.amount_corrected')->count())->toBe(1);
    expect(AppNotification::where('user_id', $this->superAdmin->id)->where('title', 'Valor de carregamento corrigido')->count())->toBe(1);

    $p->update(['station_paid_at' => now()]);
    $this->patchJson("/api/v1/admin/pickups/{$p->id}", ['amount' => 1])->assertStatus(422);
});

it('provincial de outra província não corrige nem vê carregamentos de outros condutores', function () {
    $outra = Province::factory()->create();
    $adminOutra = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $outra->id]);
    $p = giveDriverStock($this->driver, 10, $this->station, 12000);
    Sanctum::actingAs($adminOutra);

    $this->patchJson("/api/v1/admin/pickups/{$p->id}", ['amount' => 1])->assertStatus(403);
    expect($this->getJson('/api/v1/admin/pickups')->assertOk()->json('data.pickups'))->toBe([]);
});

it('só o Super Admin reinicia o contador, e o histórico fica guardado', function () {
    giveDriverStock($this->driver, 10, $this->station);
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/stations/{$this->station->id}/counter-reset")->assertStatus(403);

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/stations/{$this->station->id}/counter-reset")->assertOk()->assertJsonPath('data.bottles_before', 10);

    expect(StationCounterReset::count())->toBe(1);
    $this->travel(1)->seconds();
    giveDriverStock($this->driver, 3, $this->station);

    $r = $this->getJson('/api/v1/admin/pickups')->assertOk();
    $c = collect($r->json('data.counters'))->firstWhere('station_id', $this->station->id);
    expect($c['since_reset'])->toBe(3)->and($c['last_reset_bottles'])->toBe(10)
        ->and($r->json('data.resets.0.bottles_before'))->toBe(10);

    // o histórico de carregamentos nunca se apaga
    expect(BottlePickup::count())->toBe(2);
});

it('o contador e o pagamento funcionam mesmo depois de o posto ser apagado (antes dava 404)', function () {
    $p = giveDriverStock($this->driver, 15, $this->station, 12000);
    $this->station->delete();

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/stations/{$this->station->id}/counter-reset")->assertOk()->assertJsonPath('data.bottles_before', 15);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/billing/stations/{$this->station->id}/pay")->assertOk();
    expect($p->fresh()->station_paid_at)->not->toBeNull();
});

