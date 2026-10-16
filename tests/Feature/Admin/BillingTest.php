<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\AppNotification;
use App\Models\BottlePickup;
use App\Models\DriverCashReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Faturação da KeruGas como intermediária (Outubro 2026): o Admin
 * introduz o valor das garrafas e a comissão; o total é a soma. O pedido
 * não tem posto (sai do stock do condutor). Só ENTREGUES contam, pelo dia
 * da entrega. Cada ADMIN vê só os pedidos que confirmou. O que se deve aos
 * postos vem dos carregamentos.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);

    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->province->id]);

    $this->station = Station::factory()->create(['name' => 'Posto Central']);

    $this->order = GasOrder::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'status' => 'CONTACTING',
    ]);
});


function confirmPayload(array $o = []): array
{
    return array_merge([
        'bottle_price' => 1200,
        'commission' => 500,
        'cylinder_types_confirmed' => 'P13',
    ], $o);
}

function billingDeliveredOrder(array $o = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'province_id' => test()->province->id,
        'municipality_id' => test()->municipality->id,
        'status' => 'DELIVERED',
        'bottle_price' => 1200,
        'commission' => 500,
        'delivery_price' => 1700,
        'price_set_by' => test()->admin->id,
        'price_set_at' => now(),
        'completed_at' => now(),
    ], $o));
}

function provinceDriver(): User
{
    return User::factory()->create([
        'role' => UserRole::DRIVER, 'province_id' => test()->province->id, 'municipality_id' => test()->municipality->id,
    ]);
}

it('recusa confirmar um pedido sem os dois valores', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", ['cylinder_types_confirmed' => 'P13'])
        ->assertStatus(422)->assertJsonValidationErrors(['bottle_price', 'commission']);
    expect($this->order->fresh())->status->value->toBe('CONTACTING')->delivery_price->toBeNull();
});

it('recusa valores negativos', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", confirmPayload(['bottle_price' => -1, 'commission' => -5]))
        ->assertStatus(422)->assertJsonValidationErrors(['bottle_price', 'commission']);
});

it('confirma com valor das garrafas + comissão, sem posto, e o total é a soma', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", confirmPayload())->assertOk();

    expect($this->order->fresh())
        ->status->value->toBe('CONFIRMED')
        ->delivery_price->toEqual(1700)
        ->bottle_price->toEqual(1200)
        ->commission->toEqual(500)
        ->price_set_by->toBe($this->admin->id);
});

it('ignora um delivery_price enviado à mão — o total é sempre a soma', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", confirmPayload(['delivery_price' => 9999]))->assertOk();

    expect($this->order->fresh())->delivery_price->toEqual(1700);
});

it('permite comissão 0 (entrega de cortesia)', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", confirmPayload(['commission' => 0]))->assertOk();

    expect($this->order->fresh())->delivery_price->toEqual(1200);
});

it('o número de garrafas pode subir mas nunca diminuir', function () {
    $this->order->update(['quantity' => 3]);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", confirmPayload(['quantity' => 2]))
        ->assertStatus(422)->assertJsonValidationErrors('quantity');
    expect($this->order->fresh())->quantity->toBe(3);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", confirmPayload(['quantity' => 4]))->assertOk();
    expect($this->order->fresh())->quantity->toBe(4);
});

it('o cliente nunca vê o valor das garrafas nem a comissão, só o total', function () {
    $order = billingDeliveredOrder(['customer_phone' => '+244923000111']);

    $this->getJson("/api/v1/orders/reference/{$order->order_reference}/track?customer_phone=923000111")
        ->assertOk()
        ->assertJsonMissingPath('data.bottle_price')
        ->assertJsonMissingPath('data.commission');

    $dono = User::factory()->create(['role' => UserRole::USER]);
    $order->update(['user_id' => $dono->id]);
    Sanctum::actingAs($dono);
    $this->getJson("/api/v1/orders/reference/{$order->order_reference}")
        ->assertOk()->assertJsonMissingPath('data.bottle_price')->assertJsonMissingPath('data.commission');
});

it('o admin vê a divisão no detalhe do pedido', function () {
    $order = billingDeliveredOrder();
    Sanctum::actingAs($this->admin);

    $this->getJson("/api/v1/admin/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.bottle_price', 1200)
        ->assertJsonPath('data.commission', 500)
        ->assertJsonPath('data.billing_complete', true);
});

it('recusa a um ADMIN comum corrigir um preço já registado', function () {
    $order = billingDeliveredOrder();
    Sanctum::actingAs($this->admin);

    $this->patchJson("/api/v1/admin/orders/{$order->id}/price", ['bottle_price' => 1, 'commission' => 1])->assertStatus(403);
    expect($order->fresh())->delivery_price->toEqual(1700);
});

it('o SUPER_ADMIN corrige a divisão, o total recalcula e fica auditado', function () {
    $order = billingDeliveredOrder();
    Sanctum::actingAs($this->superAdmin);

    $this->patchJson("/api/v1/admin/orders/{$order->id}/price", ['bottle_price' => 1300, 'commission' => 700])->assertOk();

    expect($order->fresh())
        ->delivery_price->toEqual(2000)
        ->bottle_price->toEqual(1300)
        ->commission->toEqual(700)
        ->price_set_by->toBe($this->admin->id)
        ->price_corrected_by->toBe($this->superAdmin->id);

    $log = AuditLog::where('action', 'order.price_corrected')->first();
    expect($log->old_values['delivery_price'])->toEqual(1700)
        ->and($log->new_values['delivery_price'])->toEqual(2000);
});

it('recusa corrigir o preço de um pedido que ainda não tem preço registado', function () {
    Sanctum::actingAs($this->superAdmin);

    $this->patchJson("/api/v1/admin/orders/{$this->order->id}/price", ['delivery_price' => 4200])->assertStatus(422);
});

// --- Pedidos antigos (só total) ---

it('um pedido antigo só com total entra na lista por completar', function () {
    billingDeliveredOrder(['bottle_price' => null, 'commission' => null, 'delivery_price' => 3000]);
    Sanctum::actingAs($this->admin);

    $r = $this->getJson('/api/v1/admin/billing')->assertOk();

    expect($r->json('data.legacy.count'))->toBe(1)
        ->and($r->json('data.summary.legacy_orders'))->toBe(1)
        ->and($r->json('data.summary.commission_total'))->toEqual(0);
});

it('completar um pedido antigo exige que a soma seja igual ao total (sem posto)', function () {
    $order = billingDeliveredOrder(['bottle_price' => null, 'commission' => null, 'delivery_price' => 3000, 'quantity' => 2]);
    Sanctum::actingAs($this->admin);

    $bad = ['bottle_price' => 2000, 'commission' => 500, 'quantity' => 2];
    $this->patchJson("/api/v1/admin/orders/{$order->id}/billing", $bad)->assertStatus(422)->assertJsonValidationErrors('bottle_price');

    $this->patchJson("/api/v1/admin/orders/{$order->id}/billing", ['commission' => 600, 'bottle_price' => 2400] + $bad)->assertOk();

    expect($order->fresh())->bottle_price->toEqual(2400)->commission->toEqual(600)->delivery_price->toEqual(3000);
    expect(AuditLog::where('action', 'order.billing_completed')->count())->toBe(1);
});

it('completar um pedido antigo não deixa diminuir as garrafas', function () {
    $order = billingDeliveredOrder(['bottle_price' => null, 'commission' => null, 'delivery_price' => 3000, 'quantity' => 3]);
    Sanctum::actingAs($this->admin);

    $this->patchJson("/api/v1/admin/orders/{$order->id}/billing", ['bottle_price' => 2400, 'commission' => 600, 'quantity' => 2])
        ->assertStatus(422)->assertJsonValidationErrors('quantity');
});

// --- Faturação por dia ---

it('só entregues contam, pelo dia da entrega; cancelados não dão comissão', function () {
    billingDeliveredOrder();
    billingDeliveredOrder(['bottle_price' => 2400, 'commission' => 600, 'delivery_price' => 3000, 'quantity' => 2]);
    billingDeliveredOrder(['completed_at' => now()->subDays(2)]); // outro dia
    GasOrder::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'status' => 'CANCELLED',
        'bottle_price' => 1200, 'commission' => 500, 'delivery_price' => 1700, 'price_set_by' => $this->admin->id, 'completed_at' => now(),
    ]);
    GasOrder::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'status' => 'CONFIRMED',
        'bottle_price' => 1200, 'commission' => 500, 'delivery_price' => 1700, 'price_set_by' => $this->admin->id,
    ]);
    Sanctum::actingAs($this->admin);

    $r = $this->getJson('/api/v1/admin/billing')->assertOk();

    expect($r->json('data.summary.orders'))->toBe(2)
        ->and($r->json('data.summary.bottles'))->toBe(3)
        ->and($r->json('data.summary.collected_total'))->toEqual(4700)
        ->and($r->json('data.summary.bottles_total'))->toEqual(3600)
        ->and($r->json('data.summary.commission_total'))->toEqual(1100);
    expect(count($r->json('data.daily')))->toBe(14);
});

it('o parâmetro date escolhe o dia civil de Angola', function () {
    billingDeliveredOrder(['completed_at' => now('Africa/Luanda')->subDays(3)->setTime(12, 0)]);
    Sanctum::actingAs($this->admin);

    $day = now('Africa/Luanda')->subDays(3)->format('Y-m-d');
    $r = $this->getJson("/api/v1/admin/billing?date={$day}")->assertOk();

    expect($r->json('data.date'))->toBe($day)->and($r->json('data.summary.orders'))->toBe(1);
    $this->getJson('/api/v1/admin/billing?date=ontem')->assertStatus(422);
});

it('cada ADMIN só vê os pedidos que ele confirmou; o SUPER_ADMIN vê tudo com detalhe por admin', function () {
    $outroAdmin = User::factory()->admin()->create(['province_id' => $this->province->id, 'name' => 'Admin Dois']);

    billingDeliveredOrder();
    billingDeliveredOrder(['price_set_by' => $outroAdmin->id, 'commission' => 900, 'delivery_price' => 2100]);

    Sanctum::actingAs($this->admin);
    $r = $this->getJson('/api/v1/admin/billing')->assertOk();
    expect($r->json('data.scope'))->toBe('own')
        ->and($r->json('data.summary.orders'))->toBe(1)
        ->and($r->json('data.summary.commission_total'))->toEqual(500)
        ->and($r->json('data.by_admin'))->toBeNull();

    Sanctum::actingAs($this->superAdmin);
    $r = $this->getJson('/api/v1/admin/billing')->assertOk();
    expect($r->json('data.scope'))->toBe('all')->and($r->json('data.summary.orders'))->toBe(2)
        ->and($r->json('data.summary.commission_total'))->toEqual(1400)
        ->and(collect($r->json('data.by_admin'))->pluck('admin_name')->all())->toContain('Admin Dois');
});

// --- Postos (carregamentos) e pago ao posto ---

it('o que se deve a cada posto vem dos carregamentos', function () {
    $d = provinceDriver();
    giveDriverStock($d, 10, $this->station, 12000);
    giveDriverStock($d, 5, $this->station, 6000)->update(['registered_at' => now()->subDays(2)]);
    Sanctum::actingAs($this->admin);

    $r = $this->getJson('/api/v1/admin/billing')->assertOk();
    $st = collect($r->json('data.stations'))->firstWhere('station_id', $this->station->id);

    expect($st['station_name'])->toBe('Posto Central')
        ->and($st['day_bottles'])->toBe(10)
        ->and($st['day_total'])->toEqual(12000)
        ->and($st['unpaid_total'])->toEqual(18000)
        ->and($st['unconfirmed_pickups'])->toBe(2)
        ->and($r->json('data.summary.stations_unpaid_total'))->toEqual(18000);
});

it('marcar pago ao posto é tudo ou nada, fica no livro e avisa o Super Admin', function () {
    $d = provinceDriver();
    $a = giveDriverStock($d, 10, $this->station, 12000);
    giveDriverStock($d, 2, $this->station, 2400);
    Sanctum::actingAs($this->admin);

    $r = $this->postJson("/api/v1/admin/billing/stations/{$this->station->id}/pay")->assertOk();

    expect($r->json('data.amount'))->toEqual(14400)->and($r->json('data.pickups'))->toBe(2);
    expect($a->fresh())->station_paid_at->not->toBeNull()->station_paid_by->toBe($this->admin->id);
    expect(AuditLog::where('action', 'billing.station_paid')->count())->toBe(1);
    expect(AppNotification::where('user_id', $this->superAdmin->id)->where('title', 'Pagamento a posto registado')->count())->toBe(1);

    $this->postJson("/api/v1/admin/billing/stations/{$this->station->id}/pay")->assertStatus(422);
    expect($this->getJson('/api/v1/admin/billing')->json('data.summary.stations_unpaid_total'))->toEqual(0);
});

it('o pagamento só paga até à data pedida', function () {
    $d = provinceDriver();
    $old = giveDriverStock($d, 3, $this->station, 3600);
    $old->update(['registered_at' => now()->subDays(3)]);
    $new = giveDriverStock($d, 3, $this->station, 3600);
    Sanctum::actingAs($this->admin);

    $until = now('Africa/Luanda')->subDay()->format('Y-m-d');
    $this->postJson("/api/v1/admin/billing/stations/{$this->station->id}/pay", ['until' => $until])->assertOk();

    expect($old->fresh()->station_paid_at)->not->toBeNull()
        ->and($new->fresh()->station_paid_at)->toBeNull();
});

it('um ADMIN não paga carregamentos de condutores de outra província', function () {
    $outra = Province::factory()->create();
    $d = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $outra->id]);
    $p = giveDriverStock($d, 3, $this->station, 3600);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/billing/stations/{$this->station->id}/pay")->assertStatus(422);
    expect($p->fresh()->station_paid_at)->toBeNull();
});

it('só o SUPER_ADMIN desfaz um pagamento ao posto', function () {
    $p = giveDriverStock(provinceDriver(), 3, $this->station, 3600);
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/billing/stations/{$this->station->id}/pay")->assertOk();
    $log = AuditLog::where('action', 'billing.station_paid')->first();

    $this->postJson("/api/v1/admin/billing/ledger/{$log->id}/undo")->assertStatus(403);
    $this->getJson('/api/v1/admin/billing/ledger')->assertStatus(403);

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/billing/ledger/{$log->id}/undo")->assertOk();
    expect($p->fresh()->station_paid_at)->toBeNull();

    $this->postJson("/api/v1/admin/billing/ledger/{$log->id}/undo")->assertStatus(422);

    $paid = collect($this->getJson('/api/v1/admin/billing/ledger')->assertOk()->json('data'))->firstWhere('id', $log->id);
    expect($paid['undone'])->toBeTrue()->and($paid['can_undo'])->toBeFalse();
});

// --- Dinheiro dos condutores ---

function driverWithCash(): User
{
    $d = provinceDriver();
    billingDeliveredOrder(['assigned_to' => $d->id]);
    billingDeliveredOrder(['assigned_to' => $d->id, 'bottle_price' => 2400, 'commission' => 600, 'delivery_price' => 3000]);

    return $d;
}

it('mostra quanto cada condutor tem por entregar e o seu stock', function () {
    $d = driverWithCash();
    giveDriverStock($d, 8);
    Sanctum::actingAs($this->admin);

    $row = collect($this->getJson('/api/v1/admin/billing')->json('data.drivers'))->firstWhere('driver_id', $d->id);

    expect($row['collected_total'])->toEqual(4700)->and($row['outstanding'])->toEqual(4700)
        ->and($row['stock']['available'])->toBe(8);
});

it('o dinheiro do condutor conta todos os pedidos dele, mesmo confirmados por outro admin', function () {
    $outroAdmin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $d = provinceDriver();
    billingDeliveredOrder(['assigned_to' => $d->id, 'price_set_by' => $outroAdmin->id]);
    Sanctum::actingAs($this->admin);

    $row = collect($this->getJson('/api/v1/admin/billing')->json('data.drivers'))->firstWhere('driver_id', $d->id);
    expect($row['outstanding'])->toEqual(1700);
});

it('regista recebimentos parciais, avisa o condutor e nunca aceita acima do em dívida', function () {
    $d = driverWithCash();
    Sanctum::actingAs($this->admin);

    $r = $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 1700, 'note' => 'manhã'])->assertCreated();
    expect($r->json('data.outstanding'))->toEqual(3000);
    expect(AppNotification::where('user_id', $d->id)->where('title', 'Dinheiro recebido')->count())->toBe(1);
    expect(AuditLog::where('action', 'billing.cash_received')->count())->toBe(1);

    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 3000.01])
        ->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 0])->assertStatus(422);
    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 3000])->assertCreated();

    expect((float) DriverCashReceipt::where('driver_id', $d->id)->sum('amount'))->toEqual(4700.0);
});

it('um ADMIN não recebe dinheiro de um condutor de outra província', function () {
    $outra = Province::factory()->create();
    $d = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $outra->id]);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 10])->assertStatus(403);
    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 10])->assertStatus(422);
});

it('desfazer um recebimento cria um estorno e repõe o em dívida', function () {
    $d = driverWithCash();
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 1000])->assertCreated();
    $log = AuditLog::where('action', 'billing.cash_received')->first();

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/billing/ledger/{$log->id}/undo")->assertOk();

    expect(DriverCashReceipt::where('driver_id', $d->id)->count())->toBe(2)
        ->and((float) DriverCashReceipt::where('driver_id', $d->id)->sum('amount'))->toBe(0.0);
    $row = collect($this->getJson('/api/v1/admin/billing')->json('data.drivers'))->firstWhere('driver_id', $d->id);
    expect($row['outstanding'])->toEqual(4700);
});

it('um pedido ainda em negociação (sem preço) nunca entra na faturação', function () {
    Sanctum::actingAs($this->admin);

    $r = $this->getJson('/api/v1/admin/billing')->assertOk();
    expect($r->json('data.summary.orders'))->toBe(0)->and($r->json('data.legacy.count'))->toBe(0);
});
