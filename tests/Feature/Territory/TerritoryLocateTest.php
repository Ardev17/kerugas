<?php

use App\Enums\StationStatus;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Services\Territory\TerritoryLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->huila = Province::factory()->create(['name' => 'Huíla', 'slug' => 'huila']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango', 'slug' => 'lubango']);
    $this->luanda = Province::factory()->create(['name' => 'Luanda', 'slug' => 'luanda']);
    $this->talatona = Municipality::factory()->create(['province_id' => $this->luanda->id, 'name' => 'Talatona', 'slug' => 'talatona']);
});

it('normaliza nomes: sem acentos nem prefixos administrativos', function () {
    expect(TerritoryLocator::normalize('Província da Huíla'))->toBe('huila')
        ->and(TerritoryLocator::normalize('Huila Province'))->toBe('huila')
        ->and(TerritoryLocator::normalize('Município do Lubango'))->toBe('lubango')
        ->and(TerritoryLocator::normalize('Kilamba Kiaxi'))->toBe(TerritoryLocator::normalize('Quilamba Quiaxi'))
        ->and(TerritoryLocator::normalize('Kwanza Norte'))->toBe(TerritoryLocator::normalize('Cuanza Norte'))
        ->and(TerritoryLocator::normalize('Tômbwa'))->toBe(TerritoryLocator::normalize('Tombua'));
});

it('deduz província e município pela geocodificação inversa', function () {
    Http::fake(['*' => Http::response(['address' => ['state' => 'Huíla Province', 'county' => 'Município do Lubango']])]);

    $this->getJson('/api/v1/territory/locate?latitude=-14.9177&longitude=13.4925')
        ->assertOk()
        ->assertJsonPath('data.province_id', $this->huila->id)
        ->assertJsonPath('data.municipality_id', $this->lubango->id)
        ->assertJsonPath('data.source', 'geocoder');
});

it('sem resposta do geocodificador, usa o posto ativo mais próximo', function () {
    Http::fake(['*' => Http::response('erro', 503)]);
    Station::factory()->create([
        'municipality_id' => $this->talatona->id, 'status' => StationStatus::ACTIVE,
        'latitude' => -8.9147, 'longitude' => 13.1858,
    ]);

    $this->getJson('/api/v1/territory/locate?latitude=-8.92&longitude=13.19')
        ->assertOk()
        ->assertJsonPath('data.municipality_id', $this->talatona->id)
        ->assertJsonPath('data.province_id', $this->luanda->id)
        ->assertJsonPath('data.source', 'station');
});

it('quando não consegue deduzir devolve nulls (o utilizador escolhe)', function () {
    Http::fake(['*' => Http::response(['address' => ['state' => 'Nowhere']])]);

    $this->getJson('/api/v1/territory/locate?latitude=-12.0&longitude=17.0')
        ->assertOk()
        ->assertJsonPath('data.province_id', null)
        ->assertJsonPath('data.municipality_id', null);
});

it('valida as coordenadas', function () {
    $this->getJson('/api/v1/territory/locate?latitude=200&longitude=13')->assertStatus(422);
    $this->getJson('/api/v1/territory/locate')->assertStatus(422);
});
