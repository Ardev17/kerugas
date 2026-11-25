<?php

use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
});

it('regista um novo utilizador com sucesso e devolve um token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ana Silva',
        'phone' => '923456789',
        'password' => 'Senha@123',
        'password_confirmation' => 'Senha@123',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['user', 'token']]);

    // O telefone deve ficar guardado já normalizado (secção 52).
    expect(User::first()->phone)->toBe('+244923456789');
});

it('nunca guarda a password em texto puro', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ana Silva',
        'phone' => '923456789',
        'password' => 'Senha@123',
        'password_confirmation' => 'Senha@123',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    expect(User::first()->password)->not->toBe('Senha@123');
});

it('rejeita registo com número de telefone já existente (após normalização)', function () {
    User::factory()->create(['phone' => '+244923456789']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Outro Utilizador',
        'phone' => '0923456789', // mesma linha, formato diferente
        'password' => 'Senha@123',
        'password_confirmation' => 'Senha@123',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $response->assertStatus(422);
});

it('rejeita registo sem telefone (campo obrigatório)', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ana Silva',
        'password' => 'Senha@123',
        'password_confirmation' => 'Senha@123',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('phone');
});
