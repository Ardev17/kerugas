<?php

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationStatusUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Âmbito por província para exportação de dados (pedido explícito do
 * cliente, Setembro 2026): um ADMIN só pode exportar dados da SUA
 * própria província — o filtro `province_id` é sempre forçado ao
 * valor da conta, mesmo que o pedido tente enviar outro. Um ADMIN sem
 * província atribuída é bloqueado por completo (403), em vez de
 * arriscar uma exportação nacional por acidente (ver a armadilha do
 * `empty(0)` documentada em `StationExportController`).
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

    StationStatusUpdate::factory()->create(['station_id' => $this->stationBenguela->id]);
    StationStatusUpdate::factory()->create(['station_id' => $this->stationHuambo->id]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

it('um ADMIN só exporta dados da sua própria província, mesmo pedindo outra', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->get('/api/v1/admin/stations/export?' . http_build_query([
        'format' => 'csv',
        'period' => 'month',
        'province_id' => $this->huambo->id, // tentativa de contornar — deve ser ignorada
    ]));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain($this->stationBenguela->name)
        ->and($csv)->not->toContain($this->stationHuambo->name);
});

it('um ADMIN sem província atribuída é bloqueado ao tentar exportar (nunca exporta dados nacionais por engano)', function () {
    $adminSemProvincia = User::factory()->admin()->create(['province_id' => null]);
    Sanctum::actingAs($adminSemProvincia);

    $response = $this->get('/api/v1/admin/stations/export?' . http_build_query([
        'format' => 'csv',
        'period' => 'month',
    ]));

    $response->assertStatus(403);
});

it('o SUPER_ADMIN exporta dados de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->get('/api/v1/admin/stations/export?' . http_build_query([
        'format' => 'csv',
        'period' => 'month',
    ]));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain($this->stationBenguela->name)
        ->and($csv)->toContain($this->stationHuambo->name);
});
