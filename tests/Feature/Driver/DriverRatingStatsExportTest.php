<?php

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use App\Services\GasOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Avaliação do condutor + ranking/estatísticas + exportação de
 * entregas por condutor (pedido explícito do cliente, Setembro 2026),
 * incluindo o fluxo ponta-a-ponta pedido nas mesmas instruções:
 * criar pedido -> confirmar -> atribuir a condutor -> condutor conclui
 * -> cliente avalia -> estatísticas refletem -> exportação inclui.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->ganda = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Ganda']);

    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);

    $this->driver = User::factory()->create([
        'role' => UserRole::DRIVER,
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'driver_available' => true,
    ]);
    giveDriverStock($this->driver);

    $this->order = GasOrder::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'customer_phone' => '+244923456789',
        'status' => 'CONFIRMED',
        'delivery_price' => 5000,
    ]);
});

// --- Avaliação do condutor ---

it('não permite avaliar um pedido que ainda não foi entregue', function () {
    $response = $this->getJson("/api/v1/orders/reference/{$this->order->order_reference}/rating?customer_phone=923456789");

    $response->assertOk();
    $response->assertJsonPath('data.eligible', false);
    $response->assertJsonPath('data.already_rated', false);
    $response->assertJsonPath('data.ineligible_reason', 'Este pedido ainda não foi entregue.');
});

it('recusa a consulta/avaliação quando o telefone não corresponde ao pedido', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    $response = $this->getJson("/api/v1/orders/reference/{$this->order->order_reference}/rating?customer_phone=900000000");

    $response->assertStatus(403);

    $storeResponse = $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/rating", [
        'customer_phone' => '900000000',
        'rating' => 5,
    ]);

    $storeResponse->assertStatus(403);
});

it('não permite avaliar um pedido entregue por alguém que não é Condutor', function () {
    $order = GasOrder::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'customer_phone' => '+244911111111',
        'status' => 'DELIVERED',
        'assigned_to' => $this->adminBenguela->id,
        'completed_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/orders/reference/{$order->order_reference}/rating?customer_phone=911111111");

    $response->assertOk();
    $response->assertJsonPath('data.eligible', false);
    $response->assertJsonPath('data.ineligible_reason', 'Este pedido não foi entregue por um condutor.');
});

it('o cliente consegue avaliar uma entrega feita por um Condutor, usando referência + telefone (sem login)', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    $statusResponse = $this->getJson("/api/v1/orders/reference/{$this->order->order_reference}/rating?customer_phone=923456789");
    $statusResponse->assertOk();
    $statusResponse->assertJsonPath('data.eligible', true);
    $statusResponse->assertJsonPath('data.driver_name', $this->driver->name);

    $storeResponse = $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/rating", [
        // Formato local (sem +244) — a normalização deve equivaler ao
        // guardado no pedido (que já está em formato canónico).
        'customer_phone' => '923456789',
        'rating' => 4,
        'comment' => 'Entrega rápida, condutor simpático.',
    ]);

    $storeResponse->assertCreated();
    $storeResponse->assertJsonPath('data.rating', 4);

    $this->assertDatabaseHas('driver_ratings', [
        'gas_order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'rating' => 4,
    ]);
});

it('não permite avaliar o mesmo pedido duas vezes', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/rating", [
        'customer_phone' => '923456789',
        'rating' => 5,
    ])->assertCreated();

    $second = $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/rating", [
        'customer_phone' => '923456789',
        'rating' => 1,
    ]);

    $second->assertStatus(422);
    expect(\App\Models\DriverRating::where('gas_order_id', $this->order->id)->count())->toBe(1);
});

it('rejeita uma nota fora do intervalo 1-5', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    $response = $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/rating", [
        'customer_phone' => '923456789',
        'rating' => 6,
    ]);

    $response->assertStatus(422);
});

// --- Ranking/estatísticas simples ---

it('o próprio Condutor vê as suas estatísticas', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    $this->postJson("/api/v1/orders/reference/{$this->order->order_reference}/rating", [
        'customer_phone' => '923456789',
        'rating' => 5,
    ])->assertCreated();

    $cancelledOrder = GasOrder::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'status' => 'CONFIRMED',
    ]);
    app(GasOrderService::class)->assign($cancelledOrder, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->cancel($cancelledOrder, $this->driver);

    Sanctum::actingAs($this->driver);
    $response = $this->getJson('/api/v1/driver/stats');

    $response->assertOk();
    $response->assertJsonPath('data.total_delivered', 1);
    $response->assertJsonPath('data.total_cancelled', 1);
    $response->assertJsonPath('data.cancellation_rate', 50);
    $response->assertJsonPath('data.ratings_count', 1);
    $response->assertJsonPath('data.average_rating', 5);
});

it('o Admin (da mesma província) vê as estatísticas de um Condutor', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    Sanctum::actingAs($this->adminBenguela);
    $response = $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/stats");

    $response->assertOk();
    $response->assertJsonPath('data.total_delivered', 1);
});

it('o Super Admin vê as estatísticas de qualquer Condutor', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    Sanctum::actingAs($this->superAdmin);
    $response = $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/stats");

    $response->assertOk();
    $response->assertJsonPath('data.total_delivered', 1);
});

it('um Admin de outra província não consegue ver as estatísticas de um Condutor fora do seu âmbito', function () {
    $outraProvincia = Province::factory()->create();
    $adminOutraProvincia = User::factory()->admin()->create(['province_id' => $outraProvincia->id]);

    Sanctum::actingAs($adminOutraProvincia);
    $response = $this->getJson("/api/v1/admin/drivers/{$this->driver->id}/stats");

    $response->assertStatus(404);
});

// --- Exportação de entregas por condutor ---

it('o Super Admin consegue exportar as entregas dos condutores em CSV', function () {
    app(GasOrderService::class)->assign($this->order, $this->driver, $this->adminBenguela);
    app(GasOrderService::class)->complete($this->order, $this->driver);

    Sanctum::actingAs($this->superAdmin);
    $response = $this->getJson('/api/v1/admin/drivers/export?format=csv&period=month');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

it('um Admin sem província atribuída não consegue exportar entregas de condutores', function () {
    $adminSemProvincia = User::factory()->admin()->create(['province_id' => null]);

    Sanctum::actingAs($adminSemProvincia);
    $response = $this->getJson('/api/v1/admin/drivers/export?format=csv&period=month');

    $response->assertStatus(403);
});

// --- Fluxo ponta-a-ponta ---

it('fluxo completo: criar pedido -> confirmar -> atribuir a condutor -> condutor conclui -> cliente avalia -> estatísticas e exportação refletem', function () {
    // 1. Cliente cria o pedido (rota pública).
    $createResponse = $this->postJson('/api/v1/orders', [
        'customer_name' => 'Cliente Teste',
        'customer_phone' => '944555666',
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'location_source' => 'MANUAL',
        'quantity' => 1,
        'cylinder_type' => 'P13',
    ]);
    $createResponse->assertCreated();
    $reference = $createResponse->json('data.reference');
    $order = GasOrder::where('order_reference', $reference)->firstOrFail();

    // 2. Admin confirma o preço e as garrafas.
    Sanctum::actingAs($this->adminBenguela);
    $this->postJson("/api/v1/admin/orders/{$order->id}/confirm", [
        'bottle_price' => 5000,
        'commission' => 1000,
        'cylinder_types_confirmed' => 'P13',
    ])->assertOk();

    // 3. Admin atribui ao Condutor (mesmo município).
    $this->postJson("/api/v1/admin/orders/{$order->id}/assign-driver", [
        'driver_id' => $this->driver->id,
    ])->assertOk();

    // 4. Condutor marca como Feita.
    Sanctum::actingAs($this->driver);
    $this->postJson("/api/v1/driver/orders/{$order->id}/complete")->assertOk();

    // 5. Cliente avalia (sem login, referência + telefone).
    $this->postJson("/api/v1/orders/reference/{$reference}/rating", [
        'customer_phone' => '944555666',
        'rating' => 5,
        'comment' => 'Muito bom serviço.',
    ])->assertCreated();

    // 6. Estatísticas do Condutor refletem a entrega + avaliação.
    Sanctum::actingAs($this->driver);
    $statsResponse = $this->getJson('/api/v1/driver/stats');
    $statsResponse->assertOk();
    $statsResponse->assertJsonPath('data.total_delivered', 1);
    $statsResponse->assertJsonPath('data.average_rating', 5);

    // 7. Exportação (Super Admin) inclui a entrega.
    Sanctum::actingAs($this->superAdmin);
    $exportResponse = $this->getJson('/api/v1/admin/drivers/export?format=csv&period=month');
    $exportResponse->assertOk();
    $csvContent = $exportResponse->streamedContent();
    expect($csvContent)->toContain($reference);
});
