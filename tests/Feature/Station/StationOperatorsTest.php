<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Vários operadores por posto (Outubro 2026): um posto pode ter mais de um
 * operador, mas um operador só pertence a um posto.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->station = Station::factory()->create(['province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'name' => 'Posto A']);
    $this->other = Station::factory()->create(['province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'name' => 'Posto B']);
    $this->ana = User::factory()->create(['name' => 'Ana']);
    $this->bruno = User::factory()->create(['name' => 'Bruno']);
});

function stationPayload(Station $s, array $extra = []): array
{
    return [
        'name' => $s->name, 'province_id' => $s->province_id, 'municipality_id' => $s->municipality_id,
        'latitude' => (float) $s->latitude, 'longitude' => (float) $s->longitude, 'status' => 'ACTIVE',
    ] + $extra;
}

it('o Super Admin atribui dois operadores ao mesmo posto', function () {
    Sanctum::actingAs($this->super);

    $r = $this->putJson("/api/v1/admin/stations/{$this->station->id}", stationPayload($this->station, ['operator_ids' => [$this->ana->id, $this->bruno->id]]))
        ->assertOk();

    expect($r->json('data.operators'))->toHaveCount(2)
        ->and($this->ana->fresh()->operated_station_id)->toBe($this->station->id)
        ->and($this->bruno->fresh()->operated_station_id)->toBe($this->station->id)
        ->and($this->ana->fresh()->role)->toBe(UserRole::STATION_OPERATOR)
        ->and($this->bruno->fresh()->role)->toBe(UserRole::STATION_OPERATOR);
});

it('um operador não pode pertencer a dois postos', function () {
    assignOperator($this->ana, $this->station);
    $this->ana->update(['role' => UserRole::STATION_OPERATOR]);
    Sanctum::actingAs($this->super);

    $this->putJson("/api/v1/admin/stations/{$this->other->id}", stationPayload($this->other, ['operator_ids' => [$this->ana->id]]))
        ->assertStatus(422)->assertJsonValidationErrors('operator_ids');

    expect($this->ana->fresh()->operated_station_id)->toBe($this->station->id);
});

it('retirar um operador devolve-o a utilizador comum e mantém os outros', function () {
    Sanctum::actingAs($this->super);
    $this->putJson("/api/v1/admin/stations/{$this->station->id}", stationPayload($this->station, ['operator_ids' => [$this->ana->id, $this->bruno->id]]))->assertOk();
    $this->putJson("/api/v1/admin/stations/{$this->station->id}", stationPayload($this->station, ['operator_ids' => [$this->bruno->id]]))->assertOk();

    expect($this->ana->fresh()->operated_station_id)->toBeNull()
        ->and($this->ana->fresh()->role)->toBe(UserRole::USER)
        ->and($this->bruno->fresh()->operated_station_id)->toBe($this->station->id);
});

it('editar sem enviar operadores não mexe nos operadores', function () {
    assignOperator($this->ana, $this->station);
    Sanctum::actingAs($this->super);

    $this->putJson("/api/v1/admin/stations/{$this->station->id}", stationPayload($this->station, ['name' => 'Posto A2']))->assertOk();

    expect($this->ana->fresh()->operated_station_id)->toBe($this->station->id);
});

it('qualquer operador do posto pode confirmar um carregamento', function () {
    assignOperator($this->ana, $this->station);
    assignOperator($this->bruno, $this->station);
    $this->ana->update(['role' => UserRole::STATION_OPERATOR]);
    $this->bruno->update(['role' => UserRole::STATION_OPERATOR]);

    $driver = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id]);
    $pickup = giveDriverStock($driver, 4, $this->station);

    // qualquer operador do posto pode confirmar
    Sanctum::actingAs($this->bruno);
    $this->postJson("/api/v1/operator/pickups/{$pickup->id}/confirm")->assertOk();
});

it('apagar o posto liberta todos os operadores', function () {
    assignOperator($this->ana, $this->station);
    assignOperator($this->bruno, $this->station);
    Sanctum::actingAs($this->super);

    $this->deleteJson("/api/v1/admin/stations/{$this->station->id}")->assertOk();

    expect($this->ana->fresh()->operated_station_id)->toBeNull()
        ->and($this->bruno->fresh()->operated_station_id)->toBeNull();
    expect(AppNotification::count())->toBe(0);
});
