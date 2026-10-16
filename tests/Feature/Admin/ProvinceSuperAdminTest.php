<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\BottlePickup;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationCounterReset;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GasOrderService;
use App\Services\OrderAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Super Admin Provincial (Outubro 2026): os poderes do Super Admin, mas só
 * na província ligada à conta. Nunca configurações gerais nem contas de
 * Super Admin. Pode haver vários por província; ele e o Super Admin geral
 * são avisados das ações da província.
 */
beforeEach(function () {
    Queue::fake();

    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);
    $this->luanda = Province::factory()->create(['name' => 'Luanda']);
    $this->viana = Municipality::factory()->create(['province_id' => $this->luanda->id, 'name' => 'Viana']);

    $this->global = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->psa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->huila->id, 'name' => 'Provincial Huíla']);
    $this->adminHuila = User::factory()->admin()->create(['province_id' => $this->huila->id, 'name' => 'Admin Huíla']);
    $this->adminLuanda = User::factory()->admin()->create(['province_id' => $this->luanda->id, 'name' => 'Admin Luanda']);

    $this->stationHuila = Station::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'name' => 'Posto Lubango']);
    $this->stationLuanda = Station::factory()->create(['province_id' => $this->luanda->id, 'municipality_id' => $this->viana->id, 'name' => 'Posto Viana']);
});

function psaOrder(Province $p, Municipality $m, array $o = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'province_id' => $p->id, 'municipality_id' => $m->id, 'status' => 'DELIVERED',
        'bottle_price' => 1200, 'commission' => 500, 'delivery_price' => 1700,
        'price_set_at' => now(), 'completed_at' => now(),
    ], $o));
}

function psaDriver(Province $p, Municipality $m): User
{
    return User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $p->id, 'municipality_id' => $m->id, 'driver_available' => true]);
}

// --- papel e contas ---

it('o papel tem nome próprio e exige província', function () {
    expect(UserRole::PROVINCE_SUPER_ADMIN->label())->toBe('Super Admin Provincial');

    Sanctum::actingAs($this->global);
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Sem província', 'phone' => '923111222', 'password' => 'segredo123', 'role' => 'PROVINCE_SUPER_ADMIN',
    ])->assertStatus(422)->assertJsonValidationErrors('province_id');

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Provincial 2', 'phone' => '923111223', 'password' => 'segredo123', 'role' => 'PROVINCE_SUPER_ADMIN', 'province_id' => $this->huila->id,
    ])->assertCreated();

    // pode haver mais de um por província
    expect(User::where('role', 'PROVINCE_SUPER_ADMIN')->where('province_id', $this->huila->id)->count())->toBe(2);
});

it('só o Super Admin geral cria ou altera contas de Super Admin', function () {
    Sanctum::actingAs($this->psa);

    foreach (['PROVINCE_SUPER_ADMIN', 'SUPER_ADMIN'] as $i => $role) {
        $this->postJson('/api/v1/admin/users', [
            'name' => 'X', 'phone' => '92311133' . $i, 'password' => 'segredo123', 'role' => $role, 'province_id' => $this->huila->id,
        ])->assertForbidden();
    }

    $outroPsa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->huila->id]);
    $this->putJson("/api/v1/admin/users/{$outroPsa->id}", ['status' => 'SUSPENDED'])->assertForbidden();
    $this->putJson("/api/v1/admin/users/{$this->adminHuila->id}", ['role' => 'PROVINCE_SUPER_ADMIN'])->assertForbidden();
    $this->putJson("/api/v1/admin/users/{$this->psa->id}", ['status' => 'SUSPENDED'])->assertStatus(422); // a própria conta
});

it('cria Admins e Condutores da sua província, e só da sua', function () {
    Sanctum::actingAs($this->psa);

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Novo Admin', 'phone' => '923222111', 'password' => 'segredo123', 'role' => 'ADMIN', 'province_id' => $this->huila->id,
    ])->assertCreated();

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Novo Condutor', 'phone' => '923222112', 'password' => 'segredo123', 'role' => 'DRIVER', 'sms_pin' => '4821',
        'province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id,
    ])->assertCreated();

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Admin fora', 'phone' => '923222113', 'password' => 'segredo123', 'role' => 'ADMIN', 'province_id' => $this->luanda->id,
    ])->assertForbidden();
});

it('gere Admins da sua província (promover, limite de SMS) mas não muda a província de ninguém', function () {
    Sanctum::actingAs($this->psa);

    $this->putJson("/api/v1/admin/users/{$this->adminHuila->id}", ['can_promote_users' => true, 'sms_daily_limit' => 50])->assertOk();
    expect($this->adminHuila->fresh())->can_promote_users->toBeTrue()->sms_daily_limit->toBe(50);

    $this->putJson("/api/v1/admin/users/{$this->adminHuila->id}", ['province_id' => $this->luanda->id])
        ->assertStatus(422)->assertJsonValidationErrors('province_id');
    $this->putJson("/api/v1/admin/users/{$this->adminLuanda->id}", ['status' => 'SUSPENDED'])->assertForbidden();
});

it('a lista de utilizadores mostra só a sua província', function () {
    Sanctum::actingAs($this->psa);

    $ids = collect($this->getJson('/api/v1/admin/users?per_page=100')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($this->adminHuila->id)->not->toContain($this->adminLuanda->id);
});

// --- pedidos ---

it('vê e gere pedidos da sua província e corrige valores; nunca de outra', function () {
    $huila = psaOrder($this->huila, $this->lubango, ['price_set_by' => $this->adminHuila->id]);
    $luanda = psaOrder($this->luanda, $this->viana, ['price_set_by' => $this->adminLuanda->id]);
    Sanctum::actingAs($this->psa);

    $ids = collect($this->getJson('/api/v1/admin/orders?status=DELIVERED')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([$huila->id]);

    $this->patchJson("/api/v1/admin/orders/{$huila->id}/price", ['bottle_price' => 1300, 'commission' => 600])->assertOk();
    expect($huila->fresh()->delivery_price)->toEqual(1900);

    $this->patchJson("/api/v1/admin/orders/{$luanda->id}/price", ['bottle_price' => 1, 'commission' => 1])->assertForbidden();
    $this->getJson("/api/v1/admin/orders/{$luanda->id}")->assertForbidden();
});

it('marca como feito um pedido de condutor da sua província (como o Super Admin)', function () {
    $driver = psaDriver($this->huila, $this->lubango);
    giveDriverStock($driver, 5);
    $order = psaOrder($this->huila, $this->lubango, ['status' => 'CONFIRMED', 'completed_at' => null]);
    app(GasOrderService::class)->assign($order, $driver, $this->adminHuila);

    Sanctum::actingAs($this->adminHuila);
    $this->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertForbidden();

    Sanctum::actingAs($this->psa);
    $this->postJson("/api/v1/admin/orders/{$order->id}/complete")->assertOk();
});

it('é avisado dos pedidos novos da sua província, e só dela', function () {
    $huila = psaOrder($this->huila, $this->lubango, ['status' => 'PENDING']);
    $luanda = psaOrder($this->luanda, $this->viana, ['status' => 'PENDING']);
    $audience = app(OrderAudience::class);

    expect($audience->admins($huila)->pluck('id'))->toContain($this->psa->id)->toContain($this->global->id);
    expect($audience->admins($luanda)->pluck('id'))->not->toContain($this->psa->id);
});

// --- faturação ---

it('vê a faturação de todos os admins da sua província, com detalhe por admin', function () {
    $outroAdminHuila = User::factory()->admin()->create(['province_id' => $this->huila->id, 'name' => 'Admin Huíla 2']);
    psaOrder($this->huila, $this->lubango, ['price_set_by' => $this->adminHuila->id]);
    psaOrder($this->huila, $this->lubango, ['price_set_by' => $outroAdminHuila->id, 'commission' => 800, 'delivery_price' => 2000]);
    psaOrder($this->luanda, $this->viana, ['price_set_by' => $this->adminLuanda->id]);
    Sanctum::actingAs($this->psa);

    $r = $this->getJson('/api/v1/admin/billing')->assertOk();
    expect($r->json('data.scope'))->toBe('province')
        ->and($r->json('data.summary.orders'))->toBe(2)
        ->and($r->json('data.summary.commission_total'))->toEqual(1300)
        ->and(collect($r->json('data.by_admin'))->pluck('admin_name')->sort()->values()->all())->toBe(['Admin Huíla', 'Admin Huíla 2']);
});

it('desfaz pagamentos da sua província e não vê o livro de outras', function () {
    $dHuila = psaDriver($this->huila, $this->lubango);
    $dLuanda = psaDriver($this->luanda, $this->viana);
    giveDriverStock($dHuila, 5, $this->stationHuila, 6000);
    giveDriverStock($dLuanda, 5, $this->stationLuanda, 6000);

    Sanctum::actingAs($this->adminHuila);
    $this->postJson("/api/v1/admin/billing/stations/{$this->stationHuila->id}/pay")->assertOk();
    Sanctum::actingAs($this->adminLuanda);
    $this->postJson("/api/v1/admin/billing/stations/{$this->stationLuanda->id}/pay")->assertOk();

    $logHuila = AuditLog::where('action', 'billing.station_paid')->where('user_id', $this->adminHuila->id)->first();
    $logLuanda = AuditLog::where('action', 'billing.station_paid')->where('user_id', $this->adminLuanda->id)->first();

    Sanctum::actingAs($this->psa);
    $ids = collect($this->getJson('/api/v1/admin/billing/ledger')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([$logHuila->id]);

    $this->postJson("/api/v1/admin/billing/ledger/{$logLuanda->id}/undo")->assertForbidden();
    $this->postJson("/api/v1/admin/billing/ledger/{$logHuila->id}/undo")->assertOk();
});

it('o Provincial e o Super Admin geral são avisados quando um admin da província paga um posto', function () {
    giveDriverStock(psaDriver($this->huila, $this->lubango), 5, $this->stationHuila, 6000);
    $psaLuanda = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->luanda->id]);

    Sanctum::actingAs($this->adminHuila);
    $this->postJson("/api/v1/admin/billing/stations/{$this->stationHuila->id}/pay")->assertOk();

    $notified = AppNotification::where('title', 'Pagamento a posto registado')->pluck('user_id');
    expect($notified)->toContain($this->psa->id)->toContain($this->global->id)
        ->not->toContain($psaLuanda->id)->not->toContain($this->adminHuila->id);
});

it('quando o Provincial age, o Super Admin geral é avisado (e ele próprio não)', function () {
    $d = psaDriver($this->huila, $this->lubango);
    psaOrder($this->huila, $this->lubango, ['assigned_to' => $d->id]);
    Sanctum::actingAs($this->psa);

    $this->postJson("/api/v1/admin/billing/drivers/{$d->id}/receipts", ['amount' => 1000])->assertCreated();

    $notified = AppNotification::where('title', 'Dinheiro de condutor recebido')->pluck('user_id');
    expect($notified)->toContain($this->global->id)->not->toContain($this->psa->id);
});

// --- carregamentos ---

it('reinicia o contador só de postos da sua província', function () {
    giveDriverStock(psaDriver($this->huila, $this->lubango), 5, $this->stationHuila);
    Sanctum::actingAs($this->psa);

    $this->postJson("/api/v1/admin/stations/{$this->stationLuanda->id}/counter-reset")->assertForbidden();
    $this->postJson("/api/v1/admin/stations/{$this->stationHuila->id}/counter-reset")->assertOk();
    expect(StationCounterReset::count())->toBe(1);
});

// --- postos e subscrições ---

it('liga o analytics e ativa a subscrição de um posto da sua província', function () {
    Sanctum::actingAs($this->psa);

    $this->postJson("/api/v1/admin/stations/{$this->stationHuila->id}/analytics-toggle")->assertOk();
    $this->postJson("/api/v1/admin/stations/{$this->stationLuanda->id}/analytics-toggle")->assertForbidden();

    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS', 'station_id' => $this->stationHuila->id, 'months' => 1])->assertCreated();
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS', 'station_id' => $this->stationLuanda->id])->assertForbidden();
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456'])->assertForbidden();
    $this->getJson('/api/v1/admin/subscriptions/lookup?phone=923123456')->assertForbidden();
});

it('vê só subscrições de postos da sua província e nunca altera preço/IBAN', function () {
    Sanctum::actingAs($this->global);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS', 'station_id' => $this->stationLuanda->id])->assertCreated();
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456'])->assertCreated();
    $luandaSub = Subscription::where('station_id', $this->stationLuanda->id)->first();

    Sanctum::actingAs($this->psa);
    expect($this->getJson('/api/v1/admin/subscriptions')->assertOk()->json('data'))->toBe([]);
    $this->postJson("/api/v1/admin/subscriptions/{$luandaSub->id}/cancel")->assertForbidden();

    $this->getJson('/api/v1/admin/subscriptions/settings')->assertOk(); // pode ver
    $this->putJson('/api/v1/admin/subscriptions/settings', ['price_analytics_kz' => 1])->assertForbidden();
});

it('cria e edita postos só na sua província e atribui operadores', function () {
    $operador = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'province_id' => $this->huila->id]);
    Sanctum::actingAs($this->psa);

    $payload = fn (Province $p, Municipality $m) => [
        'name' => 'Posto Novo ' . $p->name, 'province_id' => $p->id, 'municipality_id' => $m->id,
        'latitude' => -14.9, 'longitude' => 13.5, 'status' => 'ACTIVE', 'operator_ids' => [$operador->id],
    ];

    $id = $this->postJson('/api/v1/admin/stations', $payload($this->huila, $this->lubango))->assertCreated()->json('data.id');
    expect($operador->fresh()->operated_station_id)->toBe($id);
    $this->postJson('/api/v1/admin/stations', $payload($this->luanda, $this->viana))->assertStatus(422);
    $this->putJson("/api/v1/admin/stations/{$this->stationLuanda->id}", $payload($this->luanda, $this->viana))->assertForbidden();
});

// --- métricas ---

it('vê o painel de métricas filtrado pela sua província', function () {
    psaOrder($this->huila, $this->lubango, ['created_at' => now()->subHour()]);
    psaOrder($this->luanda, $this->viana, ['created_at' => now()->subHour()]);
    psaOrder($this->luanda, $this->viana, ['created_at' => now()->subHour()]);

    Sanctum::actingAs($this->psa);
    $r = $this->getJson('/api/v1/admin/metrics')->assertOk();
    expect($r->json('data.scope'))->toBe('province')->and($r->json('data.orders.total'))->toBe(1);

    Sanctum::actingAs($this->global);
    expect($this->getJson('/api/v1/admin/metrics')->json('data.orders.total'))->toBe(3);

    Sanctum::actingAs($this->adminHuila);
    $this->getJson('/api/v1/admin/metrics')->assertForbidden();
});

// --- notificações ---

it('envia notificações só aos utilizadores da sua província', function () {
    Sanctum::actingAs($this->psa);

    $this->postJson('/api/v1/admin/notifications-center/broadcasts', [
        'audience' => 'users', 'province_id' => $this->luanda->id, 'title' => 'Olá', 'body' => 'Aviso',
    ])->assertCreated();

    expect(Broadcast::first()->province_id)->toBe($this->huila->id); // a província é imposta

    $this->postJson('/api/v1/admin/notifications-center/broadcasts', [
        'audience' => 'users', 'municipality_id' => $this->viana->id, 'title' => 'Olá', 'body' => 'Aviso',
    ])->assertStatus(422)->assertJsonValidationErrors('municipality_id');
});

it('não vê nem cancela envios de outra província e não mexe nas campanhas automáticas', function () {
    $outro = Broadcast::create([
        'created_by' => $this->global->id, 'audience' => 'users', 'province_id' => $this->luanda->id, 'kind' => 'announcement',
        'title' => 'Luanda', 'body' => 'x', 'send_sms' => false, 'status' => 'SCHEDULED', 'scheduled_at' => now()->addDay(),
    ]);
    Sanctum::actingAs($this->psa);

    expect($this->getJson('/api/v1/admin/notifications-center/broadcasts')->assertOk()->json('data'))->toBe([]);
    $this->postJson("/api/v1/admin/notifications-center/broadcasts/{$outro->id}/cancel")->assertForbidden();
    $this->getJson('/api/v1/admin/notifications-center/campaigns')->assertForbidden();
    $this->patchJson('/api/v1/admin/notifications-center/campaigns/weekend_eve', ['enabled' => false])->assertForbidden();
});

// --- o que é só do Super Admin geral ---

it('nunca acede a configurações gerais da plataforma', function () {
    Sanctum::actingAs($this->psa);

    $this->getJson('/api/v1/admin/contact-messages')->assertForbidden();
    $this->postJson('/api/v1/admin/sms-templates', ['code' => 'X', 'name' => 'X', 'body' => 'x', 'type' => 'GENERAL'])->assertForbidden();
    $this->getJson('/api/v1/admin/sms/credits')->assertForbidden();
});

it('o chat cobre todos os municípios da sua província, e só esses', function () {
    $outroMunHuila = Municipality::factory()->create(['province_id' => $this->huila->id]);
    Sanctum::actingAs($this->psa);

    $this->postJson('/api/v1/chat/messages', ['municipality_id' => $outroMunHuila->id, 'body' => 'Bom dia'])->assertCreated();
    $this->postJson('/api/v1/chat/messages', ['municipality_id' => $this->viana->id, 'body' => 'Bom dia'])->assertForbidden();
});

it('o middleware role aceita vários papéis (bug corrigido)', function () {
    Sanctum::actingAs($this->global);
    $this->getJson('/api/v1/admin/billing/ledger')->assertOk();
    Sanctum::actingAs($this->psa);
    $this->getJson('/api/v1/admin/billing/ledger')->assertOk();
});

// --- portas laterais fechadas (revisão de segurança) ---

it('não vê nem cancela pedidos de outra província pelas rotas do cliente', function () {
    $luanda = psaOrder($this->luanda, $this->viana, ['status' => 'PENDING', 'completed_at' => null]);
    Sanctum::actingAs($this->psa);

    $this->getJson("/api/v1/orders/reference/{$luanda->order_reference}")->assertForbidden();
    $this->patchJson("/api/v1/orders/{$luanda->id}/cancel")->assertForbidden();
    expect($luanda->fresh()->status->value)->toBe('PENDING');
});

it('um Admin comum não cancela pela rota do cliente um pedido já entregue a um condutor', function () {
    $driver = psaDriver($this->huila, $this->lubango);
    $order = psaOrder($this->huila, $this->lubango, ['status' => 'CONFIRMED', 'completed_at' => null]);
    app(GasOrderService::class)->assign($order, $driver, $this->adminHuila);

    Sanctum::actingAs($this->adminHuila);
    $this->patchJson("/api/v1/orders/{$order->id}/cancel")->assertForbidden();
});

it('não age sobre atendimentos da Central de outra província', function () {
    $op = \App\Models\Operation::create([
        'admin_id' => $this->adminLuanda->id, 'phone' => '+244923000111',
        'province_id' => $this->luanda->id, 'municipality_id' => $this->viana->id,
        'status' => \App\Enums\OperationStatus::DRAFT,
    ]);
    Sanctum::actingAs($this->psa);

    $this->getJson("/api/v1/admin/operations/{$op->id}/stations")->assertForbidden();
    $this->getJson("/api/v1/admin/operations/{$op->id}/subscription")->assertForbidden();
    $this->postJson("/api/v1/admin/operations/{$op->id}/cancel")->assertForbidden();
});

it('não cria nem move um condutor para um município de outra província', function () {
    Sanctum::actingAs($this->psa);

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Condutor torto', 'phone' => '923222199', 'password' => 'segredo123', 'role' => 'DRIVER', 'sms_pin' => '4821',
        'province_id' => $this->huila->id, 'municipality_id' => $this->viana->id,
    ])->assertStatus(422)->assertJsonValidationErrors('municipality_id');

    $driver = psaDriver($this->huila, $this->lubango);
    $this->putJson("/api/v1/admin/users/{$driver->id}", ['municipality_id' => $this->viana->id])
        ->assertStatus(422)->assertJsonValidationErrors('municipality_id');
});
