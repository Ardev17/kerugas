<?php

use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
});

it('cria um pedido de gás sem exigir autenticação (secção 172)', function () {
    $response = $this->postJson('/api/v1/orders', [
        'customer_name' => 'João Manuel',
        'customer_phone' => '923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'location_source' => 'MANUAL',
        'address' => 'Rua Principal, 123',
        'quantity' => 2,
    ]);

    $response->assertCreated();
    expect(GasOrder::count())->toBe(1);
});

it('gera uma referência pública única no formato KG-YYYYMMDD-NNNNNN', function () {
    $response = $this->postJson('/api/v1/orders', [
        'customer_name' => 'João Manuel',
        'customer_phone' => '923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'location_source' => 'MANUAL',
        'quantity' => 1,
    ]);

    $reference = $response->json('data.reference');

    expect($reference)->toMatch('/^KG-\d{8}-\d{6}$/');
});

it('o pedido começa sempre com estado PENDING', function () {
    $response = $this->postJson('/api/v1/orders', [
        'customer_name' => 'João Manuel',
        'customer_phone' => '923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'location_source' => 'MANUAL',
        'quantity' => 1,
    ]);

    expect($response->json('data.status'))->toBe('PENDING');
});

it('rejeita pedido sem quantidade (campo obrigatório)', function () {
    $response = $this->postJson('/api/v1/orders', [
        'customer_name' => 'João Manuel',
        'customer_phone' => '923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'location_source' => 'MANUAL',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('quantity');
});

it('rejeita coordenadas fora dos limites válidos', function () {
    $response = $this->postJson('/api/v1/orders', [
        'customer_name' => 'João Manuel',
        'customer_phone' => '923456789',
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'location_source' => 'GPS',
        'latitude' => 200, // inválido
        'longitude' => 13.2,
        'quantity' => 1,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('latitude');
});
