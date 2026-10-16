<?php

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Âmbito por província para pedidos de gás (pedido explícito do
 * cliente, Setembro 2026): um ADMIN só vê e só consegue agir sobre
 * pedidos da sua própria província; só o SUPER_ADMIN vê tudo.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->municipalityBenguela = Municipality::factory()->create(['province_id' => $this->benguela->id]);
    $this->municipalityHuambo = Municipality::factory()->create(['province_id' => $this->huambo->id]);

    $this->orderBenguela = GasOrder::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->municipalityBenguela->id,
    ]);

    $this->orderHuambo = GasOrder::factory()->create([
        'province_id' => $this->huambo->id,
        'municipality_id' => $this->municipalityHuambo->id,
    ]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

it('um ADMIN só vê, na listagem, pedidos da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/orders');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->orderBenguela->id)
        ->and($ids)->not->toContain($this->orderHuambo->id);
});

it('o SUPER_ADMIN vê pedidos de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/orders');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->orderBenguela->id)
        ->and($ids)->toContain($this->orderHuambo->id);
});

it('um ADMIN não consegue ver o detalhe de um pedido de outra província, mesmo sabendo o ID', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson("/api/v1/admin/orders/{$this->orderHuambo->id}");

    $response->assertStatus(403);
});

it('um ADMIN não consegue agir (ex: confirmar) sobre um pedido de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson("/api/v1/admin/orders/{$this->orderHuambo->id}/confirm");

    $response->assertStatus(403);
    expect($this->orderHuambo->fresh()->status->value)->toBe('PENDING');
});

it('um ADMIN consegue agir normalmente sobre um pedido da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson("/api/v1/admin/orders/{$this->orderBenguela->id}/contact");

    $response->assertOk();
    expect($this->orderBenguela->fresh()->status->value)->toBe('CONTACTING');
});

it('um ADMIN sem província atribuída não vê nenhum pedido (rede de segurança, nunca "vê tudo")', function () {
    $adminSemProvincia = User::factory()->admin()->create(['province_id' => null]);
    Sanctum::actingAs($adminSemProvincia);

    $response = $this->getJson('/api/v1/admin/orders');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('recusa promover um utilizador a ADMIN sem indicar uma província', function () {
    Sanctum::actingAs($this->superAdmin);

    // A factory dá sempre uma província — aqui tem de ficar sem nenhuma.
    $utilizador = User::factory()->create(['province_id' => null, 'municipality_id' => null]);

    $response = $this->putJson("/api/v1/admin/users/{$utilizador->id}", [
        'role' => 'ADMIN',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('province_id');
});

it('permite promover um utilizador a ADMIN quando a província é indicada no mesmo pedido', function () {
    Sanctum::actingAs($this->superAdmin);

    $utilizador = User::factory()->create();

    $response = $this->putJson("/api/v1/admin/users/{$utilizador->id}", [
        'role' => 'ADMIN',
        'province_id' => $this->huambo->id,
    ]);

    $response->assertOk();
    expect($utilizador->fresh())
        ->role->toBe(UserRole::ADMIN)
        ->province_id->toBe($this->huambo->id);
});

it('os KPIs de "gas_orders" no dashboard também respeitam a província do ADMIN', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/dashboard');

    $response->assertOk();
    // Só existe 1 pedido em Benguela (o do Huambo fica de fora). Os contadores
    // sobrepõem-se (o mesmo pedido conta em "today" e em "pending"), por
    // isso comparam-se um a um — nunca somados.
    expect($response->json('data.gas_orders.pending'))->toBe(1)
        ->and($response->json('data.gas_orders.today'))->toBe(1);
});

it('o dashboard do SUPER_ADMIN mostra os KPIs de "gas_orders" de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/dashboard');

    $response->assertOk();
    expect($response->json('data.gas_orders.pending'))->toBe(2);
});

it('recusa remover a província de um ADMIN já existente', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->putJson("/api/v1/admin/users/{$this->adminBenguela->id}", [
        'province_id' => null,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('province_id');
});
