<?php

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Âmbito por província para denúncias (pedido explícito do cliente,
 * Setembro 2026): um ADMIN só vê e só consegue tratar denúncias de
 * postos da sua própria província; só o SUPER_ADMIN vê e trata tudo.
 * A província vem sempre do posto denunciado (`station.province_id`).
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->stationBenguela = Station::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => Municipality::factory()->create(['province_id' => $this->benguela->id])->id,
    ]);

    $this->stationHuambo = Station::factory()->create([
        'province_id' => $this->huambo->id,
        'municipality_id' => Municipality::factory()->create(['province_id' => $this->huambo->id])->id,
    ]);

    $this->reportBenguela = StationReport::factory()->create(['station_id' => $this->stationBenguela->id]);
    $this->reportHuambo = StationReport::factory()->create(['station_id' => $this->stationHuambo->id]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

it('um ADMIN só vê, na listagem, denúncias de postos da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/reports?status=OPEN');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->reportBenguela->id)
        ->and($ids)->not->toContain($this->reportHuambo->id);
});

it('o SUPER_ADMIN vê denúncias de postos de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/reports?status=OPEN');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->reportBenguela->id)
        ->and($ids)->toContain($this->reportHuambo->id);
});

it('um ADMIN não consegue tratar uma denúncia de um posto de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->patchJson("/api/v1/admin/reports/{$this->reportHuambo->id}/status", [
        'status' => 'RESOLVED',
    ]);

    $response->assertStatus(403);
    expect($this->reportHuambo->fresh()->status->value)->toBe('OPEN');
});

it('um ADMIN consegue tratar normalmente uma denúncia de um posto da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->patchJson("/api/v1/admin/reports/{$this->reportBenguela->id}/status", [
        'status' => 'RESOLVED',
    ]);

    $response->assertOk();
    expect($this->reportBenguela->fresh()->status->value)->toBe('RESOLVED');
});
