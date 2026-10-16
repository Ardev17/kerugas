<?php

/*
|--------------------------------------------------------------------------
| Configuração do Pest
|--------------------------------------------------------------------------
| Este ficheiro é lido automaticamente pelo Pest ao correr `php artisan test`
| ou `./vendor/bin/pest`. Aplica o TestCase do Laravel a todos os testes
| em tests/Feature e tests/Unit.
*/

// `uses()` funciona no Pest 2 e no Pest 3. A função `pest()` só existe no
// Pest 3 — com o Pest 2 instalado dava "Call to undefined function pest()".
uses(Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * Stock de garrafas (Outubro 2026): um condutor só começa/confirma entregas
 * com garrafas carregadas. Testes que concluem pedidos de condutor usam isto.
 */
function giveDriverStock(\App\Models\User $driver, int $quantity = 50, ?\App\Models\Station $station = null, float $amount = 0): \App\Models\BottlePickup
{
    return \App\Models\BottlePickup::create([
        'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'station_id' => ($station ?? \App\Models\Station::factory()->create())->id,
        'driver_id' => $driver->id,
        'quantity' => $quantity,
        'amount' => $amount,
        'registered_at' => now(),
    ]);
}

/** Vários operadores por posto (Outubro 2026): liga um operador ao posto e devolve o posto. */
function assignOperator(\App\Models\User $operator, \App\Models\Station $station): \App\Models\Station
{
    $operator->update(['operated_station_id' => $station->id]);

    return $station;
}
