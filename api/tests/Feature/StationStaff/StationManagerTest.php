<?php

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Admin de Posto (gerente) e Condutor de Posto,Novembro 2026.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->catumbela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Catumbela']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);

    $this->posto = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'name' => 'Posto Lobito']);
    $this->outro = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'name' => 'Posto Vizinho']);
    $this->postoHuila = Station::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'name' => 'Posto Lubango']);

    $this->global = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->psa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->benguela->id, 'can_promote_users' => true]);

    $this->gerente = User::factory()->create([
        'role' => UserRole::STATION_MANAGER, 'managed_station_id' => $this->posto->id,
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
    ]);
});

function staffPayload(array $extra = []): array
{
    static $n = 0;
    $n++;

    return [
        'name' => "Pessoa {$n}", 'phone' => '9230000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'password' => 'segredo-forte-1',
    ] + $extra;
}

// ───────────────────────── criação pelos admins

it('o Super Admin geral cria um Admin de Posto e a província/município vêm do posto', function () {
    Sanctum::actingAs($this->global);

    $r = $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_MANAGER', 'station_id' => $this->posto->id]))
        ->assertCreated();

    $u = User::find($r->json('data.id'));
    expect($u->role)->toBe(UserRole::STATION_MANAGER)
        ->and($u->managed_station_id)->toBe($this->posto->id)
        ->and($u->province_id)->toBe($this->benguela->id)
        ->and($u->municipality_id)->toBe($this->lobito->id)
        ->and($r->json('data.station.name'))->toBe('Posto Lobito');
});

it('um posto pode ter vários gerentes', function () {
    Sanctum::actingAs($this->global);
    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_MANAGER', 'station_id' => $this->posto->id]))->assertCreated();

    expect($this->posto->managers()->count())->toBe(2);
});

it('o Provincial cria gerentes só para postos da sua província', function () {
    Sanctum::actingAs($this->psa);

    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_MANAGER', 'station_id' => $this->posto->id]))->assertCreated();
    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_MANAGER', 'station_id' => $this->postoHuila->id]))->assertForbidden();
});

it('um Admin comum nunca cria um Admin de Posto', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_MANAGER', 'station_id' => $this->posto->id]))->assertForbidden();
});

it('o Admin de Posto exige um posto', function () {
    Sanctum::actingAs($this->global);

    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_MANAGER']))
        ->assertStatus(422)->assertJsonValidationErrors('station_id');
});

it('um Admin com permissão de promover cria condutores de posto da sua província', function () {
    Sanctum::actingAs($this->admin);

    $id = $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_DRIVER', 'station_id' => $this->posto->id]))
        ->assertCreated()->json('data.id');
    expect(User::find($id)->driver_station_id)->toBe($this->posto->id);

    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_DRIVER', 'station_id' => $this->postoHuila->id]))->assertForbidden();
});

it('condutor KeruGás e condutor de posto não se convertem um no outro', function () {
    $condutor = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
    $dePosto = User::factory()->create(['role' => UserRole::STATION_DRIVER, 'driver_station_id' => $this->posto->id, 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
    Sanctum::actingAs($this->global);

    $this->putJson("/api/v1/admin/users/{$condutor->id}", ['role' => 'STATION_DRIVER', 'station_id' => $this->posto->id])
        ->assertStatus(422)->assertJsonValidationErrors('role');
    $this->putJson("/api/v1/admin/users/{$dePosto->id}", ['role' => 'DRIVER'])
        ->assertStatus(422)->assertJsonValidationErrors('role');
});

it('ao deixar de ser gerente, a ligação ao posto desaparece', function () {
    Sanctum::actingAs($this->global);

    $this->putJson("/api/v1/admin/users/{$this->gerente->id}", ['role' => 'USER'])->assertOk();

    expect($this->gerente->fresh()->managed_station_id)->toBeNull();
});

// ───────────────────────── painel do gerente

it('o gerente vê o seu posto com operadores, gerentes e condutores', function () {
    $op = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->posto->id, 'municipality_id' => $this->lobito->id]);
    Sanctum::actingAs($this->gerente);

    $r = $this->getJson('/api/v1/station-manager/station')->assertOk();

    expect($r->json('data.station.id'))->toBe($this->posto->id)
        ->and($r->json('data.operators.0.id'))->toBe($op->id)
        ->and($r->json('data.managers.0.id'))->toBe($this->gerente->id)
        ->and($r->json('data.drivers'))->toBe([]);
});

it('o gerente edita as informações do posto, mas nunca o município', function () {
    Sanctum::actingAs($this->gerente);

    $this->putJson('/api/v1/station-manager/station', [
        'name' => 'Posto Lobito Centro', 'phone' => '923111222', 'opening_hours' => '07h–19h',
        'municipality_id' => $this->catumbela->id, 'status' => 'SUSPENDED',
    ])->assertOk();

    $p = $this->posto->fresh();
    expect($p->name)->toBe('Posto Lobito Centro')
        ->and($p->opening_hours)->toBe('07h–19h')
        ->and($p->municipality_id)->toBe($this->lobito->id)
        ->and($p->status->value)->toBe('ACTIVE');
});

it('o gerente atualiza o estado do gás do seu posto', function () {
    Sanctum::actingAs($this->gerente);

    $this->postJson('/api/v1/operator/station/status', ['gas_available' => true, 'queue_level' => 'LOW'])->assertOk();

    expect((bool) $this->posto->fresh()->current_gas_available)->toBeTrue();
});

it('o gerente promove a operador só pessoas do município do posto', function () {
    $doLobito = User::factory()->create(['phone' => '+244923500001', 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
    $daCatumbela = User::factory()->create(['phone' => '+244923500002', 'province_id' => $this->benguela->id, 'municipality_id' => $this->catumbela->id]);
    $semMunicipio = User::factory()->create(['phone' => '+244923500003', 'municipality_id' => null]);
    $condutor = User::factory()->create(['phone' => '+244923500004', 'role' => UserRole::DRIVER, 'municipality_id' => $this->lobito->id]);
    Sanctum::actingAs($this->gerente);

    $this->postJson('/api/v1/station-manager/operators', ['phone' => '923500001'])->assertCreated();
    expect($doLobito->fresh()->role)->toBe(UserRole::STATION_OPERATOR)
        ->and($doLobito->fresh()->operated_station_id)->toBe($this->posto->id);

    $this->postJson('/api/v1/station-manager/operators', ['phone' => '923500002'])->assertStatus(422);
    $this->postJson('/api/v1/station-manager/operators', ['phone' => '923500003'])->assertStatus(422);
    $this->postJson('/api/v1/station-manager/operators', ['phone' => '923500004'])->assertStatus(422);
    $this->postJson('/api/v1/station-manager/operators', ['phone' => '923599999'])->assertStatus(422);

    expect($daCatumbela->fresh()->role)->toBe(UserRole::USER)
        ->and($semMunicipio->fresh()->role)->toBe(UserRole::USER)
        ->and($condutor->fresh()->role)->toBe(UserRole::DRIVER);
});

it('o gerente retira operadores só do seu posto', function () {
    $meu = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->posto->id]);
    $alheio = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->outro->id]);
    Sanctum::actingAs($this->gerente);

    $this->deleteJson("/api/v1/station-manager/operators/{$meu->id}")->assertOk();
    $this->deleteJson("/api/v1/station-manager/operators/{$alheio->id}")->assertNotFound();

    expect($meu->fresh()->role)->toBe(UserRole::USER)
        ->and($meu->fresh()->operated_station_id)->toBeNull()
        ->and($alheio->fresh()->operated_station_id)->toBe($this->outro->id);
});

it('o gerente cria, suspende e retira condutores do seu posto', function () {
    Sanctum::actingAs($this->gerente);

    $this->postJson('/api/v1/station-manager/drivers', ['name' => 'Zé Condutor', 'phone' => '923600001', 'password' => 'segredo-forte-1'])
        ->assertCreated()->assertJsonPath('data.drivers.0.name', 'Zé Condutor');

    $driver = User::where('phone', '+244923600001')->firstOrFail();
    expect($driver->role)->toBe(UserRole::STATION_DRIVER)
        ->and($driver->driver_station_id)->toBe($this->posto->id)
        ->and($driver->municipality_id)->toBe($this->lobito->id);

    $this->putJson("/api/v1/station-manager/drivers/{$driver->id}", ['status' => 'SUSPENDED'])->assertOk();
    expect($driver->fresh()->status)->toBe('SUSPENDED');

    $this->deleteJson("/api/v1/station-manager/drivers/{$driver->id}")->assertOk();
    expect($driver->fresh()->role)->toBe(UserRole::USER)->and($driver->fresh()->driver_station_id)->toBeNull();
});

it('o gerente nunca mexe em condutores de outro posto nem em condutores KeruGás', function () {
    $alheio = User::factory()->create(['role' => UserRole::STATION_DRIVER, 'driver_station_id' => $this->outro->id]);
    $keru = User::factory()->create(['role' => UserRole::DRIVER, 'municipality_id' => $this->lobito->id]);
    Sanctum::actingAs($this->gerente);

    $this->putJson("/api/v1/station-manager/drivers/{$alheio->id}", ['status' => 'SUSPENDED'])->assertNotFound();
    $this->deleteJson("/api/v1/station-manager/drivers/{$keru->id}")->assertNotFound();

    expect($alheio->fresh()->status)->toBe('ACTIVE')->and($keru->fresh()->role)->toBe(UserRole::DRIVER);
});

it('o número do condutor tem de ser novo', function () {
    User::factory()->create(['phone' => '+244923600009']);
    Sanctum::actingAs($this->gerente);

    $this->postJson('/api/v1/station-manager/drivers', ['name' => 'Repetido', 'phone' => '923600009', 'password' => 'segredo-forte-1'])->assertStatus(422);
});

// ───────────────────────── o operador não cria nem promove condutores

it('o operador de posto não cria nem promove condutores', function () {
    $op = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->posto->id, 'municipality_id' => $this->lobito->id]);
    $alguem = User::factory()->create(['municipality_id' => $this->lobito->id]);
    Sanctum::actingAs($op);

    $this->postJson('/api/v1/station-manager/drivers', ['name' => 'X', 'phone' => '923600010', 'password' => 'segredo-forte-1'])->assertForbidden();
    $this->postJson('/api/v1/station-manager/operators', ['phone' => $alguem->phone])->assertForbidden();
    $this->postJson('/api/v1/admin/users', staffPayload(['role' => 'STATION_DRIVER', 'station_id' => $this->posto->id]))->assertForbidden();
    $this->putJson("/api/v1/admin/users/{$alguem->id}", ['role' => 'DRIVER'])->assertForbidden();
});

it('quem não é gerente não entra no painel do gerente', function () {
    foreach ([UserRole::USER, UserRole::DRIVER, UserRole::STATION_DRIVER, UserRole::ADMIN] as $role) {
        Sanctum::actingAs(User::factory()->create(['role' => $role, 'province_id' => $this->benguela->id]));
        $this->getJson('/api/v1/station-manager/station')->assertForbidden();
    }
});

it('um gerente sem posto recebe uma mensagem clara', function () {
    $semPosto = User::factory()->create(['role' => UserRole::STATION_MANAGER, 'managed_station_id' => null]);
    Sanctum::actingAs($semPosto);

    $this->getJson('/api/v1/station-manager/station')->assertNotFound();
});
