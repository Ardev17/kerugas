<?php

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Âmbito por província para gestão de utilizadores (pedido explícito
 * do cliente, revisto em Setembro 2026 — inicialmente esta área tinha
 * ficado de fora): um ADMIN só vê, edita, bloqueia/desbloqueia e
 * promove utilizadores DA SUA própria província. Só o SUPER_ADMIN
 * continua a gerir utilizadores de qualquer província.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->userBenguela = User::factory()->create(['province_id' => $this->benguela->id]);
    $this->userHuambo = User::factory()->create(['province_id' => $this->huambo->id]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

it('um ADMIN só vê, na listagem, utilizadores da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/users');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->userBenguela->id)
        ->and($ids)->not->toContain($this->userHuambo->id);
});

it('o SUPER_ADMIN vê utilizadores de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/users');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->userBenguela->id)
        ->and($ids)->toContain($this->userHuambo->id);
});

it('um ADMIN não consegue editar um utilizador de outra província, mesmo sabendo o ID', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->putJson("/api/v1/admin/users/{$this->userHuambo->id}", [
        'status' => 'SUSPENDED',
    ]);

    $response->assertStatus(403);
    expect($this->userHuambo->fresh()->status)->toBe('ACTIVE');
});

it('um ADMIN não consegue bloquear um utilizador de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->putJson("/api/v1/admin/users/{$this->userHuambo->id}", [
        'status' => 'SUSPENDED',
    ]);

    $response->assertStatus(403);
});

it('um ADMIN consegue bloquear/editar normalmente um utilizador da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->putJson("/api/v1/admin/users/{$this->userBenguela->id}", [
        'status' => 'SUSPENDED',
    ]);

    $response->assertOk();
    expect($this->userBenguela->fresh()->status)->toBe('SUSPENDED');
});

it('um ADMIN não consegue mudar a província de um utilizador (só o Super Admin pode)', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->putJson("/api/v1/admin/users/{$this->userBenguela->id}", [
        'province_id' => $this->huambo->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('province_id');
    expect($this->userBenguela->fresh()->province_id)->toBe($this->benguela->id);
});

it('permite a um ADMIN reenviar a mesma província já existente, sem erro (edição normal de outro campo)', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->putJson("/api/v1/admin/users/{$this->userBenguela->id}", [
        'status' => 'SUSPENDED',
        'province_id' => $this->benguela->id,
    ]);

    $response->assertOk();
});

it('o SUPER_ADMIN consegue mudar a província de um utilizador', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->putJson("/api/v1/admin/users/{$this->userBenguela->id}", [
        'province_id' => $this->huambo->id,
    ]);

    $response->assertOk();
    expect($this->userBenguela->fresh()->province_id)->toBe($this->huambo->id);
});

it('um ADMIN sem província atribuída não vê nenhum utilizador (rede de segurança, nunca "vê tudo")', function () {
    $adminSemProvincia = User::factory()->admin()->create(['province_id' => null]);
    Sanctum::actingAs($adminSemProvincia);

    $response = $this->getJson('/api/v1/admin/users');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('o KPI "users" no dashboard também respeita a província do ADMIN, para bater certo com o separador de Utilizadores', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/dashboard');

    $response->assertOk();
    // 3 em Benguela: userBenguela, o próprio adminBenguela e o superAdmin
    // (criado com província Benguela no beforeEach). O userHuambo fica de fora.
    expect($response->json('data.users.total'))->toBe(3);
});

it('o dashboard do SUPER_ADMIN mostra o KPI "users" de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/dashboard');

    $response->assertOk();
    // userBenguela + userHuambo + adminBenguela + o próprio superAdmin.
    expect($response->json('data.users.total'))->toBe(4);
});
