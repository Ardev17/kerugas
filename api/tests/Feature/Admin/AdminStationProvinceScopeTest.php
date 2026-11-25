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
 * Âmbito por província para postos (pedido explícito do cliente):
 * um ADMIN só vê postos da sua própria província e SÓ muda o ESTADO deles —
 * criar, editar e apagar é do Super Admin Provincial (na sua província) e do
 * Super Admin (em todas) (Outubro 2026).
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->municipalityBenguela = Municipality::factory()->create(['province_id' => $this->benguela->id]);
    $this->municipalityHuambo = Municipality::factory()->create(['province_id' => $this->huambo->id]);

    $this->stationBenguela = Station::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->municipalityBenguela->id,
    ]);

    $this->stationHuambo = Station::factory()->create([
        'province_id' => $this->huambo->id,
        'municipality_id' => $this->municipalityHuambo->id,
    ]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->psaBenguela = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

function validStationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Posto de Teste',
        'address' => 'Rua Teste',
        'latitude' => -12.5,
        'longitude' => 13.5,
        'phone' => '+244912345678',
        'status' => 'ACTIVE',
    ], $overrides);
}

it('um ADMIN só vê, na listagem, postos da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/stations');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->stationBenguela->id)
        ->and($ids)->not->toContain($this->stationHuambo->id);
});

it('o SUPER_ADMIN vê postos de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/stations');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->stationBenguela->id)
        ->and($ids)->toContain($this->stationHuambo->id);
});

it('um ADMIN não consegue atualizar um posto de outra província, mesmo sabendo o ID', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->putJson(
        "/api/v1/admin/stations/{$this->stationHuambo->id}",
        validStationPayload(['province_id' => $this->huambo->id, 'municipality_id' => $this->municipalityHuambo->id])
    );

    $response->assertStatus(403);
});

it('um ADMIN não consegue apagar um posto de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->deleteJson("/api/v1/admin/stations/{$this->stationHuambo->id}");

    $response->assertStatus(403);
    $this->assertDatabaseHas('stations', ['id' => $this->stationHuambo->id]);
});

it('um ADMIN não consegue mudar o estado de um posto de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson("/api/v1/admin/stations/{$this->stationHuambo->id}/status", [
        'current_gas_available' => true,
        'current_queue_level' => 'LOW',
    ]);

    $response->assertStatus(403);
});

it('um ADMIN NÃO pode editar nem apagar nem criar postos, nem na sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);
    $payload = validStationPayload(['province_id' => $this->benguela->id, 'municipality_id' => $this->municipalityBenguela->id, 'name' => 'Nome Atualizado']);

    $this->putJson("/api/v1/admin/stations/{$this->stationBenguela->id}", $payload)->assertForbidden();
    $this->deleteJson("/api/v1/admin/stations/{$this->stationBenguela->id}")->assertForbidden();
    $this->postJson('/api/v1/admin/stations', $payload)->assertForbidden();

    $this->assertDatabaseHas('stations', ['id' => $this->stationBenguela->id]);
    expect($this->stationBenguela->fresh()->name)->not->toBe('Nome Atualizado');
});

it('um ADMIN consegue mudar o estado de um posto da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $this->postJson("/api/v1/admin/stations/{$this->stationBenguela->id}/status", [
        'gas_available' => true,
        'queue_level' => 'LOW',
    ])->assertOk();
});

it('um Provincial edita e cria postos só na sua província', function () {
    Sanctum::actingAs($this->psaBenguela);

    $this->putJson(
        "/api/v1/admin/stations/{$this->stationBenguela->id}",
        validStationPayload(['province_id' => $this->benguela->id, 'municipality_id' => $this->municipalityBenguela->id, 'name' => 'Nome Atualizado'])
    )->assertOk();
    expect($this->stationBenguela->fresh()->name)->toBe('Nome Atualizado');

    $this->postJson('/api/v1/admin/stations', validStationPayload([
        'province_id' => $this->benguela->id, 'municipality_id' => $this->municipalityBenguela->id,
    ]))->assertCreated();

    $this->postJson('/api/v1/admin/stations', validStationPayload([
        'province_id' => $this->huambo->id, 'municipality_id' => $this->municipalityHuambo->id,
    ]))->assertStatus(422)->assertJsonValidationErrors('province_id');

    $this->putJson("/api/v1/admin/stations/{$this->stationHuambo->id}",
        validStationPayload(['province_id' => $this->huambo->id, 'municipality_id' => $this->municipalityHuambo->id])
    )->assertForbidden();
    $this->deleteJson("/api/v1/admin/stations/{$this->stationHuambo->id}")->assertForbidden();
});

it('o SUPER_ADMIN pode criar um posto em qualquer província', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->postJson('/api/v1/admin/stations', validStationPayload([
        'province_id' => $this->huambo->id,
        'municipality_id' => $this->municipalityHuambo->id,
    ]));

    $response->assertCreated();
});
