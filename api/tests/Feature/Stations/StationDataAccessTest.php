<?php

use App\Enums\StationDataAccessEffect;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationDataGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);

    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);

    $this->postoLobito = Station::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->lobito->id,
        'status' => 'ACTIVE',
    ]);
    $this->postoLubango = Station::factory()->create([
        'province_id' => $this->huila->id,
        'municipality_id' => $this->lubango->id,
        'status' => 'ACTIVE',
    ]);

    $this->cliente = User::factory()->create([
        'role' => UserRole::USER,
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->lobito->id,
    ]);
});

function fecharConsulta(Municipality $m, ?string $motivo = null, ?string $desde = null): void
{
    $m->forceFill([
        'station_data_blocked_from' => $desde ?? now(),
        'station_data_blocked_reason' => $motivo,
    ])->save();
}

function excepcao(User $u, StationDataAccessEffect $efeito, ?string $validoAte = null): void
{
    StationDataGrant::query()->updateOrCreate(
        ['user_id' => $u->id],
        ['effect' => $efeito, 'expires_at' => $validoAte],
    );
}

// ---------------------------------------------------------------- a consulta

it('com o município aberto, qualquer pessoa consulta sem ter conta', function () {
    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertOk();
    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(2, 'data');
});

it('com o município fechado, quem não tem sessão é convidado a entrar', function () {
    fecharConsulta($this->lobito);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")
        ->assertStatus(401)
        ->assertJsonPath('reason', 'login_required')
        ->assertJsonPath('municipality.name', 'Lobito');
});

it('com o município fechado, o cliente com sessão vê o motivo', function () {
    fecharConsulta($this->lobito, 'Período gratuito terminado no Lobito.');
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")
        ->assertStatus(403)
        ->assertJsonPath('reason', 'municipality_blocked')
        ->assertJsonPath('message', 'Período gratuito terminado no Lobito.');
});

it('uma excepção por pessoa abre a consulta num município fechado', function () {
    fecharConsulta($this->lobito);
    excepcao($this->cliente, StationDataAccessEffect::ALLOW);
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertOk();
});

it('uma excepção com prazo deixa de valer quando o prazo passa', function () {
    fecharConsulta($this->lobito);
    excepcao($this->cliente, StationDataAccessEffect::ALLOW, now()->subMinute()->toDateTimeString());
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertStatus(403);
});

it('uma pessoa bloqueada não consulta, mesmo com o município aberto', function () {
    excepcao($this->cliente, StationDataAccessEffect::DENY);
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")
        ->assertStatus(403)
        ->assertJsonPath('reason', 'user_blocked');

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(0, 'data');
});

it('com a conta bloqueada, a lista diz que o problema é a conta e não a zona', function () {
    // O município está ABERTO. Quem está fechado é a pessoa,e a lista tem
    // de o dizer, senão o cliente lê "não há postos nesta área" e pensa que a
    // aplicação está avariada.
    excepcao($this->cliente, StationDataAccessEffect::DENY);
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lobito->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonCount(0, 'meta.blocked_municipalities')
        ->assertJsonPath('meta.access_block.reason', 'user_blocked');

    $this->getJson('/api/v1/map/stations')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.access_block.reason', 'user_blocked');
});

it('sem sessão numa zona fechada, a lista convida a entrar', function () {
    fecharConsulta($this->lobito);

    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lobito->id}")
        ->assertOk()
        ->assertJsonPath('meta.access_block.reason', 'login_required')
        ->assertJsonPath('meta.blocked_municipalities.0.name', 'Lobito');
});

it('quando está tudo bem, não vem bloqueio nenhum na lista', function () {
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lobito->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.access_block', null);
});

it('quem trabalha na plataforma continua a ver tudo', function (UserRole $papel) {
    fecharConsulta($this->lobito);
    Sanctum::actingAs(User::factory()->create(['role' => $papel, 'province_id' => $this->benguela->id]));

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertOk();
})->with([UserRole::ADMIN, UserRole::DRIVER, UserRole::MODERATOR, UserRole::INVESTOR, UserRole::PROVINCE_SUPER_ADMIN]);
// O pessoal de posto conta como cliente: ver Security/BusinessModelBypassTest.

it('um bloqueio agendado para o futuro ainda não fecha nada', function () {
    fecharConsulta($this->lobito, null, now()->addDays(30)->toDateTimeString());

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertOk();
});

it('o mapa e os postos próximos escondem o município fechado e dizem qual é', function () {
    fecharConsulta($this->lobito, 'Só para assinantes.');

    $mapa = $this->getJson('/api/v1/map/stations')->assertOk();
    $mapa->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->postoLubango->id)
        ->assertJsonCount(1, 'meta.blocked_municipalities')
        ->assertJsonPath('meta.blocked_municipalities.0.name', 'Lobito')
        ->assertJsonPath('meta.blocked_municipalities.0.reason', 'Só para assinantes.');

    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lobito->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('a procura só menciona a zona fechada que ela própria apanhou', function () {
    fecharConsulta($this->lobito, 'Só assinantes.');
    fecharConsulta($this->lubango, 'Também fechado.');

    // Quem procura no Lubango ouve falar do Lubango, e só dele.
    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lubango->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonCount(1, 'meta.blocked_municipalities')
        ->assertJsonPath('meta.blocked_municipalities.0.name', 'Lubango');
});

it('uma procura numa zona aberta não fala de zonas fechadas noutro sítio', function () {
    fecharConsulta($this->lubango);

    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lobito->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(0, 'meta.blocked_municipalities');
});

it('o município que conta é o do posto, não o do perfil de quem consulta', function () {
    // O cliente é do Lobito. Fecha-se o Lubango: ele continua a ver o Lobito
    // mas não vê o Lubango, mesmo não morando lá.
    fecharConsulta($this->lubango);
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertOk();
    $this->getJson("/api/v1/stations/{$this->postoLubango->id}")->assertStatus(403);
});

// ------------------------------------------------------------------- painel

it('o Super Admin geral fecha e reabre qualquer município, e fica auditado', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->lobito->id}",
        ['blocked' => true, 'reason' => 'Fim dos 90 dias.'])
        ->assertOk()
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.reason', 'Fim dos 90 dias.');

    expect($this->lobito->fresh()->stationDataBlocked())->toBeTrue()
        ->and(AuditLog::where('action', 'station_data.municipality_blocked')->count())->toBe(1);

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->lobito->id}", ['blocked' => false])
        ->assertOk()
        ->assertJsonPath('data.blocked', false);

    expect($this->lobito->fresh()->station_data_blocked_from)->toBeNull()
        ->and(AuditLog::where('action', 'station_data.municipality_unblocked')->count())->toBe(1);
});

it('pode agendar o fecho para a data em que os dias grátis acabam', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->lobito->id}",
        ['blocked' => true, 'from' => now()->addDays(90)->toDateTimeString()])
        ->assertOk()
        ->assertJsonPath('data.blocked', false)
        ->assertJsonPath('data.scheduled', true);

    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertOk();
});

it('o Super Admin Provincial só mexe na sua própria província', function () {
    Sanctum::actingAs(User::factory()->create([
        'role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id,
    ]));

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->lobito->id}", ['blocked' => true])
        ->assertOk();

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->lubango->id}", ['blocked' => true])
        ->assertStatus(403);

    $this->getJson('/api/v1/admin/station-data-access/municipalities')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Lobito');
});

it('o Provincial não abre excepções a pessoas de outra província', function () {
    $forasteiro = User::factory()->create(['role' => UserRole::USER, 'province_id' => $this->huila->id]);

    Sanctum::actingAs(User::factory()->create([
        'role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id,
    ]));

    $this->putJson("/api/v1/admin/station-data-access/users/{$this->cliente->id}", ['effect' => 'ALLOW'])
        ->assertOk()
        ->assertJsonPath('data.effect', 'ALLOW');

    $this->putJson("/api/v1/admin/station-data-access/users/{$forasteiro->id}", ['effect' => 'ALLOW'])
        ->assertStatus(403);
});

it('voltar a INHERIT apaga a excepção e devolve a pessoa à regra do município', function () {
    fecharConsulta($this->lobito);
    excepcao($this->cliente, StationDataAccessEffect::ALLOW);

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));
    $this->putJson("/api/v1/admin/station-data-access/users/{$this->cliente->id}", ['effect' => 'INHERIT'])
        ->assertOk()
        ->assertJsonPath('data.effect', 'INHERIT');

    expect(StationDataGrant::where('user_id', $this->cliente->id)->exists())->toBeFalse();

    Sanctum::actingAs($this->cliente);
    $this->getJson("/api/v1/stations/{$this->postoLobito->id}")->assertStatus(403);
});

it('recusa um prazo de validade que já passou', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));

    $this->putJson("/api/v1/admin/station-data-access/users/{$this->cliente->id}",
        ['effect' => 'ALLOW', 'expires_at' => now()->subDay()->toDateTimeString()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('expires_at');
});

it('o Admin comum e o cliente não chegam a estas rotas', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN, 'province_id' => $this->benguela->id]));
    $this->getJson('/api/v1/admin/station-data-access/municipalities')->assertStatus(403);

    Sanctum::actingAs($this->cliente);
    $this->getJson('/api/v1/admin/station-data-access/municipalities')->assertStatus(403);

    auth()->forgetGuards();
    $this->getJson('/api/v1/admin/station-data-access/municipalities')->assertStatus(401);
});
