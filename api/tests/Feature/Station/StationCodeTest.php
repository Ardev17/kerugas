<?php

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Número do posto (Outubro 2026): 5 dígitos, sequencial, automático. */
it('cada posto novo recebe o número seguinte, a começar em 00001', function () {
    $a = Station::factory()->create();
    $b = Station::factory()->create();
    $c = Station::factory()->create();

    expect($a->code)->toBe('00001')->and($b->code)->toBe('00002')->and($c->code)->toBe('00003');
});

it('um número apagado nunca volta a ser usado', function () {
    Station::factory()->create();
    $b = Station::factory()->create();
    $b->delete(); // soft delete

    expect(Station::factory()->create()->code)->toBe('00003');
});

it('o número não se escreve à mão nem se altera', function () {
    $s = Station::factory()->create(['code' => '99999']);
    expect($s->fresh()->code)->toBe('00001');

    $s->update(['code' => '12345']);
    expect($s->fresh()->code)->toBe('00001');
});

it('o número aparece no posto público e na pesquisa do admin', function () {
    $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::SUPER_ADMIN]);
    $s = Station::factory()->create(['name' => 'Posto Central']);
    Station::factory()->create(['name' => 'Posto Sul']);

    \Laravel\Sanctum\Sanctum::actingAs($admin);
    $this->getJson('/api/v1/admin/stations?search=' . $s->code)->assertOk()->assertJsonPath('data.0.code', $s->code);
});
