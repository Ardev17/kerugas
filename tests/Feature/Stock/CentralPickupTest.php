<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Registo de carregamentos pela central (Outubro 2026): interruptor por
 * condutor (só Super Admin / Provincial), registo por Admin da província,
 * confirmação por Super Admin / condutor / posto, e só então conta para pagar.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->other = Province::factory()->create();
    $this->operator = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    $this->station = assignOperator($this->operator, Station::factory()->create(['name' => 'Posto Norte']));

    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->provincial = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->province->id]);
    $this->driver = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->province->id, 'status' => 'ACTIVE']);
});

function cpSwitch(User $by, User $driver, bool $enabled = true, array $extra = [])
{
    Sanctum::actingAs($by);

    return test()->putJson("/api/v1/admin/drivers/{$driver->id}/central-pickup", array_merge(
        ['enabled' => $enabled, 'reason' => 'O condutor está sem telemóvel'], $extra));
}

function cpRegister(User $by, array $extra = [])
{
    Sanctum::actingAs($by);

    return test()->postJson('/api/v1/admin/pickups/central', array_merge([
        'driver_id' => test()->driver->id, 'station_id' => test()->station->id, 'quantity' => 10, 'amount' => 12000,
        'channel' => 'PHONE', 'reason' => 'Ligou do posto, sem telemóvel',
    ], $extra));
}

it('só o Super Admin e o Provincial ligam o registo; o Admin não', function () {
    cpSwitch($this->admin, $this->driver)->assertForbidden();
    expect($this->driver->fresh()->centralPickupActive())->toBeFalse();

    cpSwitch($this->provincial, $this->driver, true, ['days' => 3])->assertOk()->assertJsonPath('data.central_active', true);
    expect($this->driver->fresh()->centralPickupActive())->toBeTrue();
    expect(AuditLog::where('action', 'driver.central_pickup_enabled')->count())->toBe(1);
    expect(AppNotification::where('user_id', $this->driver->id)->where('title', 'Registo pela central ligado')->count())->toBe(1);

    cpSwitch($this->superAdmin, $this->driver, false)->assertOk()->assertJsonPath('data.central_active', false);
    expect($this->driver->fresh()->centralPickupActive())->toBeFalse();
});

it('o Provincial só liga condutores da sua província, e o motivo é obrigatório', function () {
    $outro = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->other->id]);
    cpSwitch($this->provincial, $outro)->assertForbidden();
    cpSwitch($this->provincial, $this->driver, true, ['reason' => ''])->assertStatus(422);
});

it('o registo caduca sozinho', function () {
    cpSwitch($this->superAdmin, $this->driver, true, ['days' => 1])->assertOk();
    $this->travel(2)->days();
    expect($this->driver->fresh()->centralPickupActive())->toBeFalse();
    cpRegister($this->admin)->assertStatus(422);
});

it('sem o interruptor ligado ninguém regista pela central', function () {
    cpRegister($this->admin)->assertStatus(422);
    cpRegister($this->superAdmin)->assertStatus(422);
    expect(BottlePickup::count())->toBe(0);
});

it('o Admin da província regista com motivo; conta no stock, avisa condutor, posto e Super Admins', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();

    $r = cpRegister($this->admin)->assertCreated()
        ->assertJsonPath('data.source', 'CENTRAL')
        ->assertJsonPath('data.awaiting_confirmation', true)
        ->assertJsonPath('data.registered_by_name', $this->admin->name)
        ->assertJsonPath('data.central_channel', 'PHONE');

    $pickup = BottlePickup::find($r->json('data.id'));
    expect($pickup->registered_by)->toBe($this->admin->id)->and($pickup->confirmed_at)->toBeNull();

    // Conta no stock do condutor já.
    Sanctum::actingAs($this->driver);
    $this->getJson('/api/v1/driver/stock')->assertOk()->assertJsonPath('data.stock.on_hand', 10);

    expect(AppNotification::where('user_id', $this->driver->id)->where('title', 'Carregamento registado pela central')->count())->toBe(1);
    expect(AppNotification::where('user_id', $this->operator->id)->where('title', 'Confirme as garrafas levadas')->count())->toBe(1);
    expect(AppNotification::where('user_id', $this->superAdmin->id)->where('title', 'Carregamento registado pela central')->count())->toBe(1);
    expect(AuditLog::where('action', 'pickup.central_registered')->count())->toBe(1);
});

it('um Admin de outra província não regista por este condutor', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();
    $forasteiro = User::factory()->admin()->create(['province_id' => $this->other->id]);
    cpRegister($forasteiro)->assertForbidden();
});

it('recusa o futuro, o muito antigo, quantidades absurdas e motivos vazios', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();

    cpRegister($this->admin, ['picked_at' => now()->addHour()->toIso8601String()])->assertStatus(422);
    cpRegister($this->admin, ['picked_at' => now()->subHours(49)->toIso8601String()])->assertStatus(422);
    cpRegister($this->admin, ['quantity' => 101])->assertStatus(422);
    cpRegister($this->admin, ['reason' => 'curto'])->assertUnprocessable();
    cpRegister($this->admin, ['channel' => 'TELEPATIA'])->assertUnprocessable();
    expect(BottlePickup::count())->toBe(0);

    cpRegister($this->admin, ['picked_at' => now()->subHours(20)->toIso8601String()])->assertCreated();
});

it('só conta para pagar ao posto depois de confirmado (e a rota é idempotente)', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();
    $uuid = (string) Str::uuid();
    $id = cpRegister($this->admin, ['client_uuid' => $uuid])->json('data.id');
    expect(cpRegister($this->admin, ['client_uuid' => $uuid])->json('data.id'))->toBe($id);
    expect(BottlePickup::count())->toBe(1);

    expect(BottlePickup::payable()->count())->toBe(0);

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/pickups/{$id}/confirm")->assertOk()->assertJsonPath('data.state', 'CONFIRMED');
    expect(BottlePickup::payable()->count())->toBe(1);
});

it('o Super Admin confirma, mas quem registou não confirma o próprio registo', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();

    $id = cpRegister($this->provincial)->assertCreated()->json('data.id');
    Sanctum::actingAs($this->provincial);
    $this->postJson("/api/v1/admin/pickups/{$id}/confirm")->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/pickups/{$id}/confirm")->assertForbidden();

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/pickups/{$id}/confirm")->assertOk();
    $this->postJson("/api/v1/admin/pickups/{$id}/confirm")->assertStatus(422);
});

it('o condutor confirma o seu registo — e não o de outro condutor', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();
    $id = cpRegister($this->admin)->json('data.id');

    $outro = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->province->id]);
    Sanctum::actingAs($outro);
    $this->postJson("/api/v1/driver/pickups/{$id}/confirm")->assertForbidden();

    Sanctum::actingAs($this->driver);
    $this->postJson("/api/v1/driver/pickups/{$id}/confirm")->assertOk()->assertJsonPath('data.state', 'CONFIRMED');
    expect(BottlePickup::payable()->count())->toBe(1);
});

it('o posto confirma pelo painel de sempre', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();
    $id = cpRegister($this->admin)->json('data.id');

    Sanctum::actingAs($this->operator);
    $this->postJson("/api/v1/operator/pickups/{$id}/confirm")->assertOk();
    expect(BottlePickup::payable()->count())->toBe(1);
});

it('se o condutor contesta, fica de fora do pagamento e os Super Admins são avisados', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();
    $id = cpRegister($this->admin)->json('data.id');

    Sanctum::actingAs($this->driver);
    $this->postJson("/api/v1/driver/pickups/{$id}/dispute", ['note' => 'Não levei estas garrafas'])->assertOk()->assertJsonPath('data.state', 'DISPUTED');

    expect(BottlePickup::payable()->count())->toBe(0);
    expect(AppNotification::where('user_id', $this->superAdmin->id)->where('title', 'Carregamento da central contestado')->count())->toBe(1);

    Sanctum::actingAs($this->superAdmin);
    $this->postJson("/api/v1/admin/pickups/{$id}/confirm")->assertStatus(422);
});

it('o condutor só confirma/contesta o que veio da central', function () {
    Sanctum::actingAs($this->driver);
    $p = $this->postJson('/api/v1/driver/pickups', ['client_uuid' => (string) Str::uuid(), 'station_id' => $this->station->id, 'quantity' => 5, 'amount' => 6000])->assertCreated()->json('data.pickup.id');
    $this->postJson("/api/v1/driver/pickups/{$p}/confirm")->assertStatus(422);
});

it('quando o condutor volta a registar pela app, o registo pela central desliga-se', function () {
    cpSwitch($this->superAdmin, $this->driver)->assertOk();
    expect($this->driver->fresh()->centralPickupActive())->toBeTrue();

    // Na app real o utilizador vem fresco da base de dados em cada pedido.
    Sanctum::actingAs($this->driver->fresh());
    $this->postJson('/api/v1/driver/pickups', ['client_uuid' => (string) Str::uuid(), 'station_id' => $this->station->id, 'quantity' => 5, 'amount' => 6000])->assertCreated();

    expect($this->driver->fresh()->centralPickupActive())->toBeFalse();
    expect(AuditLog::where('action', 'driver.central_pickup_auto_disabled')->count())->toBe(1);
});

it('um Admin que regista muitos de seguida faz soar o alarme dos Super Admins', function () {
    config(['kerugas.pickups.central_admin_alert_per_day' => 2]);
    cpSwitch($this->superAdmin, $this->driver)->assertOk();

    cpRegister($this->admin)->assertCreated();
    cpRegister($this->admin)->assertCreated();
    expect(AppNotification::where('title', 'Muitos registos pela central')->count())->toBe(0);
    cpRegister($this->admin)->assertCreated();
    expect(AppNotification::where('user_id', $this->superAdmin->id)->where('title', 'Muitos registos pela central')->count())->toBe(1);
});

it('a lista de condutores respeita a província do admin', function () {
    User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->other->id, 'name' => 'Forasteiro']);
    cpSwitch($this->superAdmin, $this->driver)->assertOk();

    Sanctum::actingAs($this->admin);
    $names = collect($this->getJson('/api/v1/admin/central-pickups/drivers')->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain($this->driver->name)->not->toContain('Forasteiro');

    $row = collect($this->getJson('/api/v1/admin/central-pickups/drivers')->json('data'))->firstWhere('id', $this->driver->id);
    expect($row['central_active'])->toBeTrue();
});

// --- Ver o PIN de carregamento (Outubro 2026) ---

it('o Super Admin Geral e o Provincial veem o PIN de carregamento; o Admin não', function () {
    Sanctum::actingAs($this->provincial);
    $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin")->assertOk()
        ->assertJsonPath('data.pin', null)->assertJsonPath('data.pin_set', false);

    $this->putJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin", ['pin' => '9051'])->assertOk();
    $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin")->assertOk()
        ->assertJsonPath('data.pin', '9051')->assertJsonPath('data.temporary', true);

    Sanctum::actingAs($this->superAdmin);
    $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin")->assertOk()->assertJsonPath('data.pin', '9051');

    Sanctum::actingAs($this->admin);
    $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin")->assertForbidden();

    expect(AuditLog::where('action', 'driver.sms_pin_viewed')->count())->toBe(3);
    // nunca em claro na base de dados nem na resposta do utilizador
    expect(\Illuminate\Support\Facades\DB::table('users')->where('id', $this->driver->id)->value('sms_pin_encrypted'))->not->toBe('9051');
    expect($this->driver->fresh()->toArray())->not->toHaveKey('sms_pin_encrypted');
});

it('o Provincial de outra província não vê o PIN', function () {
    $outro = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->other->id]);
    Sanctum::actingAs($outro);
    $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin")->assertForbidden();
});
