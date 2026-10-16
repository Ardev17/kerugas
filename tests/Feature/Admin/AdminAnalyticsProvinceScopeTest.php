<?php

use App\Enums\UserRole;
use App\Models\AnalyticsEvent;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Âmbito por província para Analytics (pedido explícito do cliente,
 * Setembro 2026). `analytics_events` não tem coluna de província —
 * o filtro passa pelo nome do município (só presente no payload de
 * `station_search`) traduzido para a lista de municípios da província
 * do ADMIN. `page_views_by_day` fica sempre nacional (sem sinal
 * geográfico nenhum no payload) — por isso não é coberto aqui.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->municipalityBenguela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->municipalityHuambo = Municipality::factory()->create(['province_id' => $this->huambo->id, 'name' => 'Caála']);

    Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->municipalityBenguela->id]);
    Station::factory()->create(['province_id' => $this->huambo->id, 'municipality_id' => $this->municipalityHuambo->id]);

    GasOrder::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->municipalityBenguela->id]);
    GasOrder::factory()->create(['province_id' => $this->huambo->id, 'municipality_id' => $this->municipalityHuambo->id]);

    AnalyticsEvent::create(['event' => 'station_search', 'payload' => ['municipality' => 'Lobito']]);
    AnalyticsEvent::create(['event' => 'station_search', 'payload' => ['municipality' => 'Caála']]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

it('um ADMIN recebe analytics com âmbito de província e apenas as suas próprias contagens', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/analytics');

    $response->assertOk();
    expect($response->json('data.scope.type'))->toBe('province')
        ->and($response->json('data.scope.province'))->toBe('Benguela');

    $totalSearches = collect($response->json('data.searches_by_day'))->sum('total');
    $totalOrders = collect($response->json('data.orders_by_day'))->sum('total');
    $topMunicipalities = collect($response->json('data.top_municipalities_searched'))->pluck('name');

    expect($totalSearches)->toBe(1)
        ->and($totalOrders)->toBe(1)
        ->and($topMunicipalities)->toContain('Lobito')
        ->and($topMunicipalities)->not->toContain('Caála');
});

it('o SUPER_ADMIN recebe analytics de âmbito nacional', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/analytics');

    $response->assertOk();
    expect($response->json('data.scope.type'))->toBe('national');

    $totalSearches = collect($response->json('data.searches_by_day'))->sum('total');
    $totalOrders = collect($response->json('data.orders_by_day'))->sum('total');

    expect($totalSearches)->toBe(2)
        ->and($totalOrders)->toBe(2);
});
