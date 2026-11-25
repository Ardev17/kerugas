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
    $postosDeBenguela = fn () => Station::where('name', 'like', '[DEMO]%')
        ->whereHas('province', fn ($q) => $q->where('slug', 'benguela'))->count();

    $this->seed([ProvinceSeeder::class, MunicipalitySeeder::class, DemoDataSeeder::class]);
    $depoisDeUmaPassagem = $postosDeBenguela();

    $this->seed(DemoDataSeeder::class);

    // O que este teste guarda não é o número exacto de postos demo,esse sobe
    // sempre que se junta um posto ao seeder, e estava preso em 6 desde que a
    // lista de Benguela cresceu. O que não pode mudar é isto: repetir o seeder
    // não duplica nada.
    expect($depoisDeUmaPassagem)->toBeGreaterThanOrEqual(6)
        ->and($postosDeBenguela())->toBe($depoisDeUmaPassagem);

    $central = Station::where('name', '[DEMO] Posto Central Benguela')->firstOrFail();
    expect($central->operators()->count())->toBe(2);

    expect(User::where('email', 'provincial.benguela@demo.kerugas.com')->value('role'))->toBe(UserRole::PROVINCE_SUPER_ADMIN)
        ->and(User::where('role', UserRole::DRIVER)->where('email', 'like', '%@demo.kerugas.com')->count())->toBe(4);

    // investidor demo: 2 municípios, sem duplicar ao repetir o seeder
    $investor = User::where('email', 'investidor.benguela@demo.kerugas.com')->firstOrFail();
    expect(\App\Models\MunicipalityInvestment::active()->where('user_id', $investor->id)->count())->toBe(2);
});
