<?php

use App\Enums\QueueLevel;
use App\Enums\StationUpdateSource;
use App\Enums\UserRole;
use App\Models\AnalyticsEvent;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationStatusUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->catumbela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Catumbela']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);

    $this->postoA = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'name' => 'Posto A']);
    $this->postoB = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->catumbela->id, 'name' => 'Posto B']);
    $this->postoHuila = Station::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'name' => 'Posto Huila']);

    $this->global = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->psaBenguela = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
});

function status(Station $s, bool $gas, $at): void
{
    StationStatusUpdate::factory()->create([
        'station_id' => $s->id, 'gas_available' => $gas,
        'queue_level' => QueueLevel::cases()[0], 'source' => StationUpdateSource::OPERATOR,
        'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('calcula o tempo total com gas no periodo a partir do historico de estados', function () {
    // Hora fixa para o intervalo caber sempre dentro do dia.
    Illuminate\Support\Carbon::setTestNow(today()->setTime(18, 0));

    status($this->postoA, true, today()->setTime(8, 0));
    status($this->postoA, false, today()->setTime(12, 0));

    Sanctum::actingAs($this->global);
    $r = $this->getJson('/api/v1/admin/metrics/municipalities?period=today')->assertOk();

    $a = collect($r->json('data.top_gas_availability'))->firstWhere('station_id', $this->postoA->id);
    expect($a['seconds_with_gas'])->toBe(4 * 3600)->and($a['label'])->toBe('4h');

    Illuminate\Support\Carbon::setTestNow();
});

it('conta o estado que ja vinha de ontem (ultimo registo antes do inicio)', function () {
    status($this->postoA, true, today()->subDay()->setTime(9, 0));

    Sanctum::actingAs($this->global);
    $r = $this->getJson('/api/v1/admin/metrics/municipalities?period=today')->assertOk();

    $a = collect($r->json('data.top_gas_availability'))->firstWhere('station_id', $this->postoA->id);
    expect($a['seconds_with_gas'])->toBeGreaterThan(0);
});

it('conta as requisicoes por municipio: station_search e station_view', function () {
    AnalyticsEvent::create(['event' => 'station_search', 'payload' => ['municipality' => 'Lobito'], 'created_at' => now()->subMinutes(10)]);
    AnalyticsEvent::create(['event' => 'station_search', 'payload' => ['municipality' => 'lobito'], 'created_at' => now()->subMinutes(10)]);
    AnalyticsEvent::create(['event' => 'station_view', 'payload' => ['station_id' => $this->postoA->id], 'created_at' => now()->subMinutes(10)]);
    AnalyticsEvent::create(['event' => 'station_view', 'payload' => ['station_id' => $this->postoB->id], 'created_at' => now()->subMinutes(10)]);
    $old = AnalyticsEvent::create(['event' => 'station_search', 'payload' => ['municipality' => 'Lobito']]);
    // created_at não é mass-assignable: forçar via query para cair fora do período.
    AnalyticsEvent::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);

    Sanctum::actingAs($this->global);
    $r = $this->getJson('/api/v1/admin/metrics/municipalities?period=today')->assertOk();

    $byMun = collect($r->json('data.municipalities'))->keyBy('municipality');
    expect($byMun['Lobito']['requests'])->toBe(3)
        ->and($byMun['Catumbela']['requests'])->toBe(1)
        ->and($r->json('data.totals.requests'))->toBe(4);
});

it('o Provincial so ve a sua provincia', function () {
    status($this->postoHuila, true, today()->setTime(8, 0));
    AnalyticsEvent::create(['event' => 'station_search', 'payload' => ['municipality' => 'Lubango'], 'created_at' => now()->subMinutes(10)]);

    Sanctum::actingAs($this->psaBenguela);
    $r = $this->getJson('/api/v1/admin/metrics/municipalities?period=today')->assertOk();

    $muns = collect($r->json('data.municipalities'))->pluck('municipality');
    expect($muns)->toContain('Lobito', 'Catumbela')->not->toContain('Lubango')
        ->and($r->json('data.scope.type'))->toBe('province')
        ->and($r->json('data.scope.province'))->toBe('Benguela')
        ->and($r->json('data.scope.can_pick_province'))->toBeFalse();
});

it('o Super Admin geral pode filtrar por provincia e por municipio', function () {
    Sanctum::actingAs($this->global);

    $r = $this->getJson("/api/v1/admin/metrics/municipalities?period=30d&province_id={$this->huila->id}")->assertOk();
    expect(collect($r->json('data.municipalities'))->pluck('municipality'))->toContain('Lubango')->not->toContain('Lobito');

    $r2 = $this->getJson("/api/v1/admin/metrics/municipalities?period=30d&municipality_id={$this->lobito->id}")->assertOk();
    expect(collect($r2->json('data.municipalities'))->pluck('municipality')->all())->toBe(['Lobito']);
});

it('recusa um municipio fora do ambito do Provincial', function () {
    Sanctum::actingAs($this->psaBenguela);
    $this->getJson("/api/v1/admin/metrics/municipalities?municipality_id={$this->lubango->id}")
        ->assertUnprocessable()->assertJsonValidationErrors('municipality_id');
});

it('aceita um intervalo a escolha e valida as datas', function () {
    Sanctum::actingAs($this->global);
    $this->getJson('/api/v1/admin/metrics/municipalities?period=custom&from=2026-11-01&to=2026-11-10')->assertOk();
    $this->getJson('/api/v1/admin/metrics/municipalities?period=custom&from=2026-11-10&to=2026-11-01')
        ->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/admin/metrics/municipalities?period=custom')->assertUnprocessable();
});

it('o Admin comum nao tem acesso', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/admin/metrics/municipalities?period=today')->assertForbidden();
});
