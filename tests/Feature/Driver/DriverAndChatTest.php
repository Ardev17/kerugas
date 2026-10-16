<?php

use App\Enums\UserRole;
use App\Models\RoomMessage;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use App\Services\GasOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Condutor + envio de pedidos + chat por município (pedido explícito
 * do cliente, Setembro 2026). Cobre as 4 regras confirmadas pelo
 * cliente antes de qualquer código ser escrito:
 *   1. Fluxo direto: ASSIGNED -> Feita/Cancelado (sem passo intermédio).
 *   2. Disponibilidade automática (na atribuição/conclusão) + manual.
 *   3. Só o SUPER_ADMIN cria/gere contas de Condutor.
 *   4. (Tempo real via Reverb — não testável sem um worker de
 *      broadcasting a correr; coberto pela resiliência de
 *      ChatService::send, que nunca falha por causa do Reverb.)
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->ganda = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Ganda']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->municipalityHuambo = Municipality::factory()->create(['province_id' => $this->huambo->id]);

    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);

    $this->driverGanda = User::factory()->create([
        'role' => UserRole::DRIVER,
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'driver_available' => true,
    ]);

    $this->driverLobito = User::factory()->create([
        'role' => UserRole::DRIVER,
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->lobito->id,
        'driver_available' => true,
    ]);
    giveDriverStock($this->driverGanda);
    giveDriverStock($this->driverLobito);

    $this->order = GasOrder::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
        'status' => 'CONFIRMED',
        'delivery_price' => 5000,
    ]);
});

// --- Criação de contas de Condutor: estritamente SUPER_ADMIN ---

it('um ADMIN não consegue criar uma conta de Condutor', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson('/api/v1/admin/users', [
        'name' => 'Novo Condutor',
        'phone' => '+244900000111',
        'password' => 'password123',
        'role' => 'DRIVER',
        'sms_pin' => '4821',
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
    ]);

    $response->assertStatus(403);
    expect(User::where('phone', '+244900000111')->exists())->toBeFalse();
});

it('o SUPER_ADMIN consegue criar uma conta de Condutor', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->postJson('/api/v1/admin/users', [
        'name' => 'Novo Condutor',
        'phone' => '+244900000111',
        'password' => 'password123',
        'role' => 'DRIVER',
        'sms_pin' => '4821',
        'province_id' => $this->benguela->id,
        'municipality_id' => $this->ganda->id,
    ]);

    $response->assertCreated();
    expect(User::where('phone', '+244900000111')->first())
        ->role->toBe(UserRole::DRIVER)
        ->municipality_id->toBe($this->ganda->id);
});

it('recusa criar um Condutor sem município', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->postJson('/api/v1/admin/users', [
        'name' => 'Novo Condutor',
        'phone' => '+244900000111',
        'password' => 'password123',
        'role' => 'DRIVER',
        'sms_pin' => '4821',
        'province_id' => $this->benguela->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('municipality_id');
});

// --- Envio a condutor: só do mesmo município, só se disponível ---

it('o Admin consegue enviar um pedido finalizado a um condutor disponível do mesmo município', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/assign-driver", [
        'driver_id' => $this->driverGanda->id,
    ]);

    $response->assertOk();
    expect($this->order->fresh())->assigned_to->toBe($this->driverGanda->id)
        ->status->toBe(\App\Enums\GasOrderStatus::ASSIGNED);
    // Disponibilidade automática: o condutor fica indisponível ao ser atribuído.
    expect($this->driverGanda->fresh()->driver_available)->toBeFalse();
});

it('recusa enviar o pedido a um condutor de outro município', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/assign-driver", [
        'driver_id' => $this->driverLobito->id,
    ]);

    $response->assertStatus(422);
    expect($this->order->fresh()->assigned_to)->toBeNull();
});

it('recusa enviar o pedido a um condutor indisponível', function () {
    $this->driverGanda->update(['driver_available' => false]);
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/assign-driver", [
        'driver_id' => $this->driverGanda->id,
    ]);

    $response->assertStatus(422);
});

// --- Conclusão/cancelamento: só o condutor ou o SUPER_ADMIN ---

it('um ADMIN não consegue marcar como concluído um pedido atribuído a um condutor', function () {
    app(GasOrderService::class)->assign($this->order, $this->driverGanda, $this->adminBenguela);

    Sanctum::actingAs($this->adminBenguela);
    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/complete");

    $response->assertStatus(403);
    expect($this->order->fresh()->status)->not->toBe(\App\Enums\GasOrderStatus::DELIVERED);
});

it('o SUPER_ADMIN consegue marcar como concluído um pedido atribuído a um condutor', function () {
    app(GasOrderService::class)->assign($this->order, $this->driverGanda, $this->adminBenguela);

    Sanctum::actingAs($this->superAdmin);
    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/complete");

    $response->assertOk();
    expect($this->order->fresh()->status)->toBe(\App\Enums\GasOrderStatus::DELIVERED);
});

it('o próprio condutor consegue marcar o seu pedido como Feita, e volta a ficar disponível', function () {
    app(GasOrderService::class)->assign($this->order, $this->driverGanda, $this->adminBenguela);
    expect($this->driverGanda->fresh()->driver_available)->toBeFalse();

    Sanctum::actingAs($this->driverGanda);
    $response = $this->postJson("/api/v1/driver/orders/{$this->order->id}/complete");

    $response->assertOk();
    expect($this->order->fresh()->status)->toBe(\App\Enums\GasOrderStatus::DELIVERED);
    expect($this->driverGanda->fresh()->driver_available)->toBeTrue();
});

it('o próprio condutor consegue marcar o seu pedido como Cancelado', function () {
    app(GasOrderService::class)->assign($this->order, $this->driverGanda, $this->adminBenguela);

    Sanctum::actingAs($this->driverGanda);
    $response = $this->postJson("/api/v1/driver/orders/{$this->order->id}/cancel", ['reason' => 'Cliente não atendeu']);

    $response->assertOk();
    expect($this->order->fresh()->status)->toBe(\App\Enums\GasOrderStatus::CANCELLED);
    expect($this->driverGanda->fresh()->driver_available)->toBeTrue();
});

it('um condutor não consegue concluir um pedido atribuído a outro condutor', function () {
    app(GasOrderService::class)->assign($this->order, $this->driverGanda, $this->adminBenguela);

    Sanctum::actingAs($this->driverLobito);
    $response = $this->postJson("/api/v1/driver/orders/{$this->order->id}/complete");

    $response->assertStatus(403);
});

it('o condutor só vê, no seu dashboard, os pedidos que lhe foram atribuídos', function () {
    $outroPedido = GasOrder::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->ganda->id]);
    app(GasOrderService::class)->assign($this->order, $this->driverGanda, $this->adminBenguela);

    Sanctum::actingAs($this->driverGanda);
    $response = $this->getJson('/api/v1/driver/orders');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->order->id)->not->toContain($outroPedido->id);
});

it('o condutor consegue alternar manualmente a sua disponibilidade', function () {
    Sanctum::actingAs($this->driverGanda);

    $response = $this->postJson('/api/v1/driver/availability');

    $response->assertOk();
    expect($this->driverGanda->fresh()->driver_available)->toBeFalse();
});

// --- Confirmação do preço + tipo(s) de garrafa juntos ---

it('a confirmação do pedido exige preço E tipo(s) de garrafa confirmados', function () {
    Sanctum::actingAs($this->adminBenguela);

    $pedidoPendente = GasOrder::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->ganda->id]);

    $response = $this->postJson("/api/v1/admin/orders/{$pedidoPendente->id}/confirm", [
        'bottle_price' => 2500,
        'commission' => 500,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('cylinder_types_confirmed');
});

it('confirma o pedido com preço e múltiplos tipos de garrafa separados por vírgula', function () {
    Sanctum::actingAs($this->adminBenguela);

    $pedidoPendente = GasOrder::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->ganda->id]);

    $response = $this->postJson("/api/v1/admin/orders/{$pedidoPendente->id}/confirm", [
        'bottle_price' => 2500,
        'commission' => 500,
        'cylinder_types_confirmed' => '6kg, 13kg',
    ]);

    $response->assertOk();
    expect($pedidoPendente->fresh())
        ->delivery_price->toEqual('3000.00')
        ->cylinder_types_confirmed->toBe('6kg, 13kg');
});

// --- Chat por município: âmbito de visibilidade ---

it('um condutor só vê mensagens do seu próprio município', function () {
    RoomMessage::create(['room_type' => RoomMessage::DRIVERS, 'user_id' => $this->adminBenguela->id, 'room_id' => $this->ganda->id, 'body' => 'Alguém conhece a Rua X na Ganda?']);
    RoomMessage::create(['room_type' => RoomMessage::DRIVERS, 'user_id' => $this->adminBenguela->id, 'room_id' => $this->lobito->id, 'body' => 'Mensagem do Lobito']);

    Sanctum::actingAs($this->driverGanda);
    $response = $this->getJson('/api/v1/chat/messages');

    $response->assertOk();
    $bodies = collect($response->json('data'))->pluck('body');
    expect($bodies)->toContain('Alguém conhece a Rua X na Ganda?')
        ->not->toContain('Mensagem do Lobito');
});

it('um Admin só vê mensagens da sua própria província, mas de qualquer município dela', function () {
    RoomMessage::create(['room_type' => RoomMessage::DRIVERS, 'user_id' => $this->driverGanda->id, 'room_id' => $this->ganda->id, 'body' => 'Da Ganda']);
    RoomMessage::create(['room_type' => RoomMessage::DRIVERS, 'user_id' => $this->driverGanda->id, 'room_id' => $this->municipalityHuambo->id, 'body' => 'Do Huambo']);

    Sanctum::actingAs($this->adminBenguela);
    $response = $this->getJson('/api/v1/chat/messages');

    $response->assertOk();
    $bodies = collect($response->json('data'))->pluck('body');
    expect($bodies)->toContain('Da Ganda')->not->toContain('Do Huambo');
});

it('o Super Admin vê mensagens de todas as províncias, com filtro opcional por município', function () {
    RoomMessage::create(['room_type' => RoomMessage::DRIVERS, 'user_id' => $this->driverGanda->id, 'room_id' => $this->ganda->id, 'body' => 'Da Ganda']);
    RoomMessage::create(['room_type' => RoomMessage::DRIVERS, 'user_id' => $this->driverGanda->id, 'room_id' => $this->municipalityHuambo->id, 'body' => 'Do Huambo']);

    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/chat/messages');
    $response->assertOk();
    expect(collect($response->json('data'))->pluck('body'))->toContain('Da Ganda', 'Do Huambo');

    $filtrado = $this->getJson("/api/v1/chat/messages?municipality_id={$this->ganda->id}");
    $filtrado->assertOk();
    expect(collect($filtrado->json('data'))->pluck('body'))->toContain('Da Ganda')->not->toContain('Do Huambo');
});

it('um condutor não consegue enviar uma mensagem para outro município', function () {
    Sanctum::actingAs($this->driverGanda);

    $response = $this->postJson('/api/v1/chat/messages', [
        'municipality_id' => $this->lobito->id,
        'body' => 'A tentar falar no Lobito',
    ]);

    $response->assertStatus(403);
});

it('um condutor consegue enviar uma mensagem para o seu próprio município', function () {
    Sanctum::actingAs($this->driverGanda);

    $response = $this->postJson('/api/v1/chat/messages', [
        'municipality_id' => $this->ganda->id,
        'body' => 'Alguém conhece o Bairro Novo?',
    ]);

    $response->assertCreated();
    expect(RoomMessage::where('body', 'Alguém conhece o Bairro Novo?')->exists())->toBeTrue();
});

it('um Admin não consegue enviar uma mensagem para um município de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->postJson('/api/v1/chat/messages', [
        'municipality_id' => $this->municipalityHuambo->id,
        'body' => 'Fora do meu âmbito',
    ]);

    $response->assertStatus(403);
});

it('um USER comum não tem acesso ao chat', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/chat/messages');

    $response->assertStatus(403);
});
