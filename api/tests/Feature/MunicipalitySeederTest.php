<?php

use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use Database\Seeders\MunicipalitySeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('cria os 326 municípios oficiais nas 21 províncias', function () {
    $this->seed([ProvinceSeeder::class, MunicipalitySeeder::class]);

    expect(Province::count())->toBe(21)
        ->and(Municipality::count())->toBe(326)
        ->and(Municipality::whereHas('province', fn ($q) => $q->where('slug', 'malanje'))->count())->toBe(27);
});

it('numa base antiga renomeia e muda de província sem duplicar nem perder ligações', function () {
    $this->seed(ProvinceSeeder::class);
    $p = fn (string $n) => Province::where('slug', Str::slug($n))->value('id');

    // Dados como estavam com o seeder anterior.
    $namibe = Municipality::create(['province_id' => $p('Namibe'), 'name' => 'Namibe', 'slug' => 'namibe']);
    $cangamba = Municipality::create(['province_id' => $p('Moxico Leste'), 'name' => 'Cangamba', 'slug' => 'cangamba']);
    $luanda = Municipality::create(['province_id' => $p('Luanda'), 'name' => 'Luanda', 'slug' => 'luanda']);
    $station = Station::factory()->create(['municipality_id' => $namibe->id]);

    $this->seed(MunicipalitySeeder::class);
    $this->seed(MunicipalitySeeder::class); // segunda vez não muda nada

    expect($namibe->fresh()->name)->toBe('Moçâmedes')
        ->and($namibe->fresh()->slug)->toBe('mocamedes')
        ->and($station->fresh()->municipality_id)->toBe($namibe->id)
        ->and($cangamba->fresh()->province_id)->toBe($p('Moxico'))
        ->and(Municipality::where('slug', 'cangamba')->count())->toBe(1)
        ->and($luanda->fresh())->not->toBeNull()              // nome extinto: fica, não se apaga
        ->and(Municipality::count())->toBe(327);              // 326 oficiais + 1 antigo
});

it('limpa a cache das listas de municípios da API', function () {
    $this->seed(ProvinceSeeder::class);
    $benguela = Province::where('slug', 'benguela')->first();
    Municipality::create(['province_id' => $benguela->id, 'name' => 'Lobito', 'slug' => 'lobito']);

    // Primeira chamada põe a lista antiga (1 município) em cache.
    $this->getJson("/api/v1/provinces/{$benguela->id}/municipalities")->assertJsonCount(1, 'data');

    $this->seed(MunicipalitySeeder::class);

    $this->getJson("/api/v1/provinces/{$benguela->id}/municipalities")->assertJsonCount(23, 'data');
});
