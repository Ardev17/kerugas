<?php

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Mapa de calor da procura (Outubro 2026). */
beforeEach(function () {
    $this->huila = Province::factory()->create();
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);
    $this->luanda = Province::factory()->create();
    $this->viana = Municipality::factory()->create(['province_id' => $this->luanda->id, 'name' => 'Viana']);

    $this->admin = User::factory()->admin()->create(['province_id' => $this->huila->id]);
    $this->psa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->huila->id]);
    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
});

function demandOrder(Province $p, Municipality $m, array $o = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'province_id' => $p->id, 'municipality_id' => $m->id, 'status' => 'DELIVERED',
        'latitude' => -14.91712, 'longitude' => 13.49231, 'neighborhood' => 'Lalula', 'quantity' => 1,
        'created_at' => now(),
    ], $o));
}

it('agrupa os pedidos numa grelha de ~110 m e conta entregues', function () {
    demandOrder($this->huila, $this->lubango);
    demandOrder($this->huila, $this->lubango, ['latitude' => -14.91748, 'longitude' => 13.49209, 'status' => 'CANCELLED']); // mesma célula
    demandOrder($this->huila, $this->lubango, ['latitude' => -14.93, 'longitude' => 13.51]);
    demandOrder($this->huila, $this->lubango, ['latitude' => null, 'longitude' => null]); // sem GPS
    Sanctum::actingAs($this->admin);

    $r = $this->getJson('/api/v1/admin/analytics/demand')->assertOk();

    expect($r->json('data.totals.orders'))->toBe(4)
        ->and($r->json('data.totals.cancelled'))->toBe(1)
        ->and($r->json('data.totals.with_location'))->toBe(3)
        ->and($r->json('data.totals.location_rate'))->toEqual(75);

    $top = $r->json('data.points.0');
    expect($top['count'])->toBe(2)->and($top['delivered'])->toBe(1)
        ->and(count($r->json('data.points')))->toBe(2);
});

it('dia da semana × hora em hora de Angola, com pico', function () {
    // Segunda-feira 23:30 UTC = terça 00:30 em Luanda.
    $utc = \Carbon\Carbon::parse('2026-09-28 23:30:00', 'UTC')->setTimezone(config('app.timezone'));
    demandOrder($this->huila, $this->lubango, ['created_at' => $utc]);
    demandOrder($this->huila, $this->lubango, ['created_at' => $utc->copy()->addMinutes(5)]);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    Sanctum::actingAs($this->admin);

    $r = $this->getJson('/api/v1/admin/analytics/demand?days=30')->assertOk();

    expect($r->json('data.weekday_hour.grid.1.0'))->toBe(2) // Ter, 00h
        ->and($r->json('data.weekday_hour.weekdays.1'))->toBe('Ter')
        ->and($r->json('data.peak.hour'))->toBe(0)
        ->and($r->json('data.peak.weekday'))->toBe('Ter')
        ->and($r->json('data.by_hour.0'))->toBe(2);
});

it('bairros normalizados por município, incluindo pedidos sem GPS', function () {
    demandOrder($this->huila, $this->lubango, ['neighborhood' => 'lalula ', 'latitude' => null, 'longitude' => null, 'quantity' => 2]);
    demandOrder($this->huila, $this->lubango, ['neighborhood' => 'LALULA']);
    demandOrder($this->huila, $this->lubango, ['neighborhood' => 'Mitcha']);
    Sanctum::actingAs($this->admin);

    $hoods = $this->getJson('/api/v1/admin/analytics/demand')->assertOk()->json('data.neighborhoods');

    expect($hoods[0])->toMatchArray(['neighborhood' => 'Lalula', 'municipality' => 'Lubango', 'orders' => 2, 'bottles' => 3]);
});

it('só conta o período escolhido', function () {
    demandOrder($this->huila, $this->lubango, ['created_at' => now()->subDays(20)]);
    demandOrder($this->huila, $this->lubango);
    Sanctum::actingAs($this->admin);

    expect($this->getJson('/api/v1/admin/analytics/demand?days=7')->json('data.totals.orders'))->toBe(1);
    expect($this->getJson('/api/v1/admin/analytics/demand?days=30')->json('data.totals.orders'))->toBe(2);
    $this->getJson('/api/v1/admin/analytics/demand?days=5')->assertStatus(422);
});

it('Admin e Super Admin Provincial só veem a sua província; o geral filtra ou vê tudo', function () {
    demandOrder($this->huila, $this->lubango);
    demandOrder($this->luanda, $this->viana);
    demandOrder($this->luanda, $this->viana);
    Station::factory()->create(['province_id' => $this->luanda->id, 'municipality_id' => $this->viana->id, 'name' => 'Posto Viana']);

    foreach ([$this->admin, $this->psa] as $who) {
        Sanctum::actingAs($who);
        $r = $this->getJson('/api/v1/admin/analytics/demand?province_id=' . $this->luanda->id)->assertOk();
        expect($r->json('data.totals.orders'))->toBe(1)->and($r->json('data.scope'))->toBe('province')
            ->and(collect($r->json('data.stations'))->pluck('name'))->not->toContain('Posto Viana');
        $this->getJson('/api/v1/admin/analytics/demand?municipality_id=' . $this->viana->id)->assertForbidden();
    }

    Sanctum::actingAs($this->super);
    expect($this->getJson('/api/v1/admin/analytics/demand')->json('data.totals.orders'))->toBe(3);
    expect($this->getJson('/api/v1/admin/analytics/demand?province_id=' . $this->luanda->id)->json('data.totals.orders'))->toBe(2);
});

it('quem não tem analytics não vê a procura', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::DRIVER]));
    $this->getJson('/api/v1/admin/analytics/demand')->assertForbidden();
});
