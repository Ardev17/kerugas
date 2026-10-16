<?php

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\MunicipalitySeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cria postos e contas demo em Benguela, com dois operadores no mesmo posto', function () {
    $this->seed([ProvinceSeeder::class, MunicipalitySeeder::class, DemoDataSeeder::class]);
    $this->seed(DemoDataSeeder::class); // repetir não duplica

    $benguela = Station::where('name', 'like', '[DEMO]%')->whereHas('province', fn ($q) => $q->where('slug', 'benguela'))->count();
    expect($benguela)->toBe(6);

    $central = Station::where('name', '[DEMO] Posto Central Benguela')->firstOrFail();
    expect($central->operators()->count())->toBe(2);

    expect(User::where('email', 'provincial.benguela@demo.kerugas.com')->value('role'))->toBe(UserRole::PROVINCE_SUPER_ADMIN)
        ->and(User::where('role', UserRole::DRIVER)->where('email', 'like', '%@demo.kerugas.com')->count())->toBe(4);

    // investidor demo: 2 municípios, sem duplicar ao repetir o seeder
    $investor = User::where('email', 'investidor.benguela@demo.kerugas.com')->firstOrFail();
    expect(\App\Models\MunicipalityInvestment::active()->where('user_id', $investor->id)->count())->toBe(2);
});
