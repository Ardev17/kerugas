<?php

use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $province = Province::factory()->create();
    $municipality = Municipality::factory()->create(['province_id' => $province->id]);

    $this->reference = $this->postJson('/api/v1/orders', [
        'customer_name' => 'João Manuel',
        'customer_phone' => '923456789',
        'province_id' => $province->id,
        'municipality_id' => $municipality->id,
        'location_source' => 'MANUAL',
        'quantity' => 2,
        'notes' => 'Portão azul',
    ])->json('data.reference');
});

it('um visitante acompanha o pedido com referência + telefone', function () {
    $response = $this->getJson("/api/v1/orders/reference/{$this->reference}/track?customer_phone=923456789");

    $response->assertOk()
        ->assertJsonPath('data.reference', $this->reference)
        ->assertJsonStructure(['data' => ['status', 'status_label', 'quantity']]);
});

it('aceita o telefone noutro formato (+244 …)', function () {
    $this->getJson("/api/v1/orders/reference/{$this->reference}/track?customer_phone=%2B244923456789")->assertOk();
});

it('nunca expõe telefone, notas, coordenadas nem histórico', function () {
    $data = $this->getJson("/api/v1/orders/reference/{$this->reference}/track?customer_phone=923456789")
        ->assertOk()->json('data');

    foreach (['customer_phone', 'customer_name', 'notes', 'latitude', 'longitude', 'events', 'delivery_price'] as $key) {
        expect($data)->not->toHaveKey($key);
    }
});

it('responde 404 a telefone errado, sem confirmar que a referência existe', function () {
    $this->getJson("/api/v1/orders/reference/{$this->reference}/track?customer_phone=924000000")->assertNotFound();
    $this->getJson('/api/v1/orders/reference/KG-00000000-000000/track?customer_phone=923456789')->assertNotFound();
});

it('exige o telefone', function () {
    $this->getJson("/api/v1/orders/reference/{$this->reference}/track")->assertStatus(422);
});

it('mostra o estado novo quando o pedido avança', function () {
    GasOrder::where('order_reference', $this->reference)->update(['status' => 'DELIVERED']);

    $this->getJson("/api/v1/orders/reference/{$this->reference}/track?customer_phone=923456789")
        ->assertOk()->assertJsonPath('data.status', 'DELIVERED');
});

it('diz que pode avaliar só quando foi entregue por um condutor e ainda sem avaliação', function () {
    $url = "/api/v1/orders/reference/{$this->reference}/track?customer_phone=923456789";
    $order = GasOrder::where('order_reference', $this->reference)->first();

    // Ainda por entregar.
    $this->getJson($url)->assertOk()->assertJsonPath('data.can_rate', false);

    // Entregue por um condutor, sem avaliação.
    $driver = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::DRIVER]);
    $order->update(['status' => 'DELIVERED', 'assigned_to' => $driver->id]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.can_rate', true);

    // Depois de avaliar, deixa de poder.
    \App\Models\DriverRating::create(['gas_order_id' => $order->id, 'driver_id' => $driver->id, 'rating' => 4]);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.can_rate', false)
        ->assertJsonPath('data.driver_rating', 4);
});

it('um pedido entregue por um Admin (não por condutor) não pode ser avaliado', function () {
    $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::ADMIN]);
    GasOrder::where('order_reference', $this->reference)->update(['status' => 'DELIVERED', 'assigned_to' => $admin->id]);

    $this->getJson("/api/v1/orders/reference/{$this->reference}/track?customer_phone=923456789")
        ->assertOk()->assertJsonPath('data.can_rate', false);
});
