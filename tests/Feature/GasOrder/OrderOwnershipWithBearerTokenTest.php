<?php

use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regressão (Setembro 2026, varredura antes do app Android).
 *
 * Estes testes usam um token Bearer REAL (createToken), tal como o site e
 * o app enviam — e não `Sanctum::actingAs()`. Isso é essencial aqui:
 * `actingAs` muda o guard por omissão para `sanctum`, e com ele
 * `$request->user()` funciona mesmo numa rota pública — escondendo
 * exatamente o bug que estes testes protegem (pedidos de clientes com
 * sessão iniciada gravados sem `user_id`).
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->orderPayload = [
        'customer_name' => 'Maria Luísa',
        'customer_phone' => '923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'location_source' => 'MANUAL',
        'address' => 'Rua da Missão, 10',
        'quantity' => 1,
    ];
});

it('liga o pedido à conta do cliente quando ele faz o pedido com sessão iniciada (Bearer token)', function () {
    $customer = User::factory()->create();
    $token = $customer->createToken('app')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', $this->orderPayload);

    $response->assertCreated();
    expect(GasOrder::first()->user_id)->toBe($customer->id);
});

it('continua a aceitar pedidos de visitantes sem conta (user_id fica vazio)', function () {
    $this->postJson('/api/v1/orders', $this->orderPayload)->assertCreated();

    expect(GasOrder::first()->user_id)->toBeNull();
});

it('o dono consegue ver o próprio pedido pela referência com o token Bearer', function () {
    $customer = User::factory()->create();
    $token = $customer->createToken('app')->plainTextToken;
    $order = GasOrder::factory()->create([
        'user_id' => $customer->id,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/reference/{$order->order_reference}")
        ->assertOk()
        ->assertJsonPath('data.reference', $order->order_reference);
});

it('outra conta não consegue ver um pedido que não é seu', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $token = $stranger->createToken('app')->plainTextToken;
    $order = GasOrder::factory()->create([
        'user_id' => $owner->id,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/reference/{$order->order_reference}")
        ->assertForbidden();
});

it('sem sessão, ver um pedido pela referência pede autenticação (401, não 403)', function () {
    $order = GasOrder::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);

    $this->getJson("/api/v1/orders/reference/{$order->order_reference}")->assertUnauthorized();
});
