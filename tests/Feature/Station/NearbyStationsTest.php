<?php

use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * "Usar minha localização" em Encontrar gás: GET /stations/nearby.
 * Cobre a distância, o raio, os filtros, o posto exatamente na posição do
 * cliente (acos > 1 por arredondamento), a validação das coordenadas e o
 * fallback por município.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->lubango = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->other = Municipality::factory()->create(['province_id' => $this->province->id]);

    // Cliente no centro do Lubango.
    $this->lat = -14.9177;
    $this->lng = 13.4925;
});

function nearbyStation(array $overrides = []): Station
{
    return Station::factory()->create(array_merge([
        'province_id' => test()->province->id,
        'municipality_id' => test()->lubango->id,
        'latitude' => test()->lat,
        'longitude' => test()->lng,
        'status' => 'ACTIVE',
    ], $overrides));
}

function nearbyGet(array $params): Illuminate\Testing\TestResponse
{
    return test()->getJson('/api/v1/stations/nearby?' . http_build_query($params));
}

it('ordena os postos do mais perto para o mais longe e devolve a distância', function () {
    $far = nearbyStation(['name' => 'Longe', 'latitude' => $this->lat + 0.08]);   // ~8.9 km
    $near = nearbyStation(['name' => 'Perto', 'latitude' => $this->lat + 0.01]);  // ~1.1 km
    $mid = nearbyStation(['name' => 'Meio', 'latitude' => $this->lat + 0.04]);    // ~4.4 km

    $data = nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->assertOk()->json('data');

    expect(collect($data)->pluck('name')->all())->toBe(['Perto', 'Meio', 'Longe'])
        ->and($data[0]['distance_km'])->toEqualWithDelta(1.11, 0.02)
        ->and($data[1]['distance_km'])->toEqualWithDelta(4.45, 0.03)
        ->and($data[2]['distance_km'])->toEqualWithDelta(8.9, 0.05);
});

it('o posto exatamente na posição do cliente aparece, com distância 0', function () {
    nearbyStation(['name' => 'No mesmo sítio']);

    $data = nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('No mesmo sítio')
        ->and($data[0]['distance_km'])->toEqualWithDelta(0.0, 0.001);
});

it('respeita o raio por omissão (15 km) e o raio pedido', function () {
    nearbyStation(['name' => '10 km', 'latitude' => $this->lat + 0.09]);
    nearbyStation(['name' => '20 km', 'latitude' => $this->lat + 0.18]);

    $default = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->json('data'))->pluck('name')->all();
    $wide = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'radius_km' => 25])->json('data'))->pluck('name')->all();
    $tight = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'radius_km' => 5])->json('data'))->pluck('name')->all();

    expect($default)->toBe(['10 km'])
        ->and($wide)->toBe(['10 km', '20 km'])
        ->and($tight)->toBe([]);
});

it('a distância na direção leste-oeste usa o cosseno da latitude (não 111 km por grau de longitude)', function () {
    // 0.05° de longitude a ~15°S ≈ 5.4 km (e não 5.5 km nem 0.05 * 111).
    nearbyStation(['name' => 'Leste', 'longitude' => $this->lng + 0.05]);

    $d = nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->json('data.0.distance_km');

    expect($d)->toEqualWithDelta(5.37, 0.05);
});

it('não mostra postos inativos, pendentes ou suspensos', function () {
    nearbyStation(['name' => 'Ativo']);
    nearbyStation(['name' => 'Inativo', 'status' => 'INACTIVE']);
    nearbyStation(['name' => 'Pendente', 'status' => 'PENDING']);
    nearbyStation(['name' => 'Suspenso', 'status' => 'SUSPENDED']);

    $names = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Ativo']);
});

it('filtra por gás disponível e por fila', function () {
    nearbyStation(['name' => 'Com gás, fila alta', 'current_gas_available' => true, 'current_queue_level' => 'HIGH']);
    nearbyStation(['name' => 'Com gás, fila baixa', 'current_gas_available' => true, 'current_queue_level' => 'LOW', 'latitude' => $this->lat + 0.01]);
    nearbyStation(['name' => 'Sem gás', 'current_gas_available' => false, 'latitude' => $this->lat + 0.02]);

    $gas = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'gas_available' => 1])->json('data'))->pluck('name')->all();
    $low = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'gas_available' => 1, 'queue_level' => 'LOW'])->json('data'))->pluck('name')->all();

    expect($gas)->toBe(['Com gás, fila alta', 'Com gás, fila baixa'])
        ->and($low)->toBe(['Com gás, fila baixa']);
});

it('devolve uma lista vazia (e não um erro) quando não há postos por perto', function () {
    nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.count', 0);
});

it('limita a 50 resultados', function () {
    Station::factory()->count(55)->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->lubango->id,
        'latitude' => $this->lat, 'longitude' => $this->lng, 'status' => 'ACTIVE',
    ]);

    nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->assertOk()->assertJsonPath('meta.count', 50);
});

it('aceita coordenadas nos limites do mundo e nega fora deles', function () {
    nearbyGet(['latitude' => 90, 'longitude' => 180])->assertOk();
    nearbyGet(['latitude' => -90, 'longitude' => -180])->assertOk();

    nearbyGet(['latitude' => 90.0001, 'longitude' => 0])->assertStatus(422)->assertJsonValidationErrors('latitude');
    nearbyGet(['latitude' => 0, 'longitude' => 180.5])->assertStatus(422)->assertJsonValidationErrors('longitude');
});

it('recusa coordenadas que não são números e pedidos sem localização nem município', function () {
    nearbyGet(['latitude' => 'abc', 'longitude' => $this->lng])->assertStatus(422)->assertJsonValidationErrors('latitude');
    nearbyGet(['latitude' => "1; DROP TABLE stations", 'longitude' => 0])->assertStatus(422)->assertJsonValidationErrors('latitude');
    nearbyGet([])->assertStatus(422);
    nearbyGet(['latitude' => $this->lat])->assertStatus(422)->assertJsonValidationErrors('longitude');
});

it('recusa raios fora de 1–100 km', function () {
    nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'radius_km' => 0])->assertStatus(422);
    nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'radius_km' => 101])->assertStatus(422);
});

it('sem coordenadas, usa o município escolhido à mão (fallback)', function () {
    nearbyStation(['name' => 'Lubango']);
    nearbyStation(['name' => 'Outro município', 'municipality_id' => $this->other->id]);

    $names = collect(nearbyGet(['municipality_id' => $this->lubango->id])->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Lubango']);
});

it('um município que não existe dá 422', function () {
    nearbyGet(['municipality_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('municipality_id');
});

it('as coordenadas do cliente têm prioridade sobre o município quando vêm as duas', function () {
    nearbyStation(['name' => 'Aqui']);
    nearbyStation(['name' => 'Noutro município', 'municipality_id' => $this->other->id, 'latitude' => $this->lat + 0.01]);

    $names = collect(nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng, 'municipality_id' => $this->other->id])->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Aqui', 'Noutro município']);
});

it('a resposta traz as coordenadas do posto em número (o "Traçar rota" depende delas)', function () {
    nearbyStation(['latitude' => -14.9177, 'longitude' => 13.4925]);

    $station = nearbyGet(['latitude' => $this->lat, 'longitude' => $this->lng])->json('data.0');

    expect($station['latitude'])->toBeFloat()->toEqualWithDelta(-14.9177, 0.00001)
        ->and($station['longitude'])->toBeFloat()->toEqualWithDelta(13.4925, 0.00001);
});

it('Station::distanceFromKm nunca devolve NaN, mesmo para o mesmo ponto', function () {
    $station = nearbyStation();

    expect($station->distanceFromKm($this->lat, $this->lng))->toEqualWithDelta(0.0, 0.001);
    expect(is_nan($station->distanceFromKm($this->lat, $this->lng)))->toBeFalse();
    // Ponto antípoda: o maior valor possível (~20015 km), sem NaN.
    $antipode = $station->distanceFromKm(-$this->lat, $this->lng + 180);
    expect(is_nan($antipode))->toBeFalse()->and($antipode)->toBeGreaterThan(19900);
});
