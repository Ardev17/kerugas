<?php

use App\Enums\StationUpdateSource;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationStatusUpdate;
use App\Services\TrustScoreService;

beforeEach(function () {
    $this->service = new TrustScoreService();

    $province = Province::factory()->create();
    $municipality = Municipality::factory()->create(['province_id' => $province->id]);

    $this->station = Station::factory()->create([
        'province_id' => $province->id,
        'municipality_id' => $municipality->id,
    ]);
});

it('devolve score 0 quando o posto nunca foi atualizado', function () {
    expect($this->service->calculate($this->station))->toBe(0);
});

it('dá score alto para uma atualização recente via SMS oficial', function () {
    StationStatusUpdate::factory()->create([
        'station_id' => $this->station->id,
        'source' => StationUpdateSource::OFFICIAL_SMS,
        'created_at' => now(),
    ]);
    $this->station->update(['status_updated_at' => now()]);

    expect($this->service->calculate($this->station))->toBeGreaterThanOrEqual(70);
});

it('dá score mais baixo para uma atualização antiga da comunidade', function () {
    StationStatusUpdate::factory()->create([
        'station_id' => $this->station->id,
        'source' => StationUpdateSource::COMMUNITY,
        'created_at' => now()->subHours(4),
    ]);
    $this->station->update(['status_updated_at' => now()->subHours(4)]);

    expect($this->service->calculate($this->station))->toBeLessThan(40);
});

it('label() devolve "Confiança alta" para scores >= 70', function () {
    expect($this->service->label(85))->toBe('Confiança alta');
});

it('label() devolve "Confiança moderada" para scores entre 40 e 69', function () {
    expect($this->service->label(50))->toBe('Confiança moderada');
});

it('label() devolve "Informação desatualizada" para scores abaixo de 40', function () {
    expect($this->service->label(10))->toBe('Informação desatualizada');
});

it('nunca devolve um score fora do intervalo 0-100', function () {
    $score = $this->service->calculate($this->station);

    expect($score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});
