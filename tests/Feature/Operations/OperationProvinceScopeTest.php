<?php

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Central de Operações (Outubro 2026): um Admin ligado a uma província só
 * atende dentro dela — a província é sempre a da conta e o município tem
 * de ser um dos seus.
 */
beforeEach(function () {
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);

    $this->admin = User::factory()->admin()->create(['province_id' => $this->huila->id]);
});

it('o Admin inicia atendimento num município da sua província', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/admin/operations', [
        'phone' => '923123456',
        'province_id' => $this->huila->id,
        'municipality_id' => $this->lubango->id,
    ])->assertCreated()->assertJsonPath('data.province_id', $this->huila->id);
});

it('o Admin não consegue atender noutra província: a da conta prevalece e o município de fora é recusado', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/admin/operations', [
        'phone' => '923123456',
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->lobito->id,
    ])->assertStatus(422)->assertJsonValidationErrors('municipality_id');
});

it('mesmo sem enviar a província, o Admin fica com a da sua conta', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/admin/operations', [
        'phone' => '923123456',
        'municipality_id' => $this->lubango->id,
    ])->assertCreated()->assertJsonPath('data.province_id', $this->huila->id);
});

it('um Admin sem província atribuída não inicia atendimentos', function () {
    Sanctum::actingAs(User::factory()->admin()->create(['province_id' => null]));

    $this->postJson('/api/v1/admin/operations', ['phone' => '923123456'])->assertForbidden();
});

it('o Super Admin atende em qualquer província, mas o município tem de pertencer-lhe', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));

    $this->postJson('/api/v1/admin/operations', [
        'phone' => '923123456', 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
    ])->assertCreated();

    $this->postJson('/api/v1/admin/operations', [
        'phone' => '923123456', 'province_id' => $this->benguela->id, 'municipality_id' => $this->lubango->id,
    ])->assertStatus(422);
});
