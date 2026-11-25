<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\GasOrderEvent;
use App\Models\SmsDispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Dados mínimos nas respostas da API (Outubro 2026): cada pessoa recebe
 * só o que o seu ecrã usa.
 */
function minimalDataTestOrder(User $customer): GasOrder
{
    $admin = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Admin Interno']);
    $order = GasOrder::factory()->create([
        'user_id' => $customer->id, 'status' => GasOrderStatus::CONFIRMED, 'customer_phone' => $customer->phone,
        'price_set_by' => $admin->id, 'price_set_at' => now(),
    ]);
    GasOrderEvent::create([
        'gas_order_id' => $order->id, 'action' => 'confirmed', 'from_status' => 'PENDING', 'to_status' => 'CONFIRMED',
        'user_id' => $admin->id, 'notes' => 'Nota interna da equipa: cliente difícil',
    ]);

    return $order;
}

it('o cliente vê o seu pedido sem o histórico interno, a prioridade nem quem fixou o preço', function () {
    $customer = User::factory()->create(['role' => UserRole::USER]);
    $order = minimalDataTestOrder($customer);
    Sanctum::actingAs($customer);

    $data = $this->getJson("/api/v1/orders/reference/{$order->order_reference}")->assertOk()->json('data');

    expect($data)->toHaveKeys(['reference', 'status', 'address', 'customer_phone', 'notes']); // o que o ecrã dele usa
    foreach (['events', 'priority', 'assigned_to_id', 'price_set_by', 'price_set_at', 'price_corrected_by', 'price_corrected_at'] as $key) {
        expect($data)->not->toHaveKey($key);
    }
    expect(json_encode($data))->not->toContain('Nota interna');

    // e na lista "Os meus pedidos"
    $list = $this->getJson('/api/v1/orders')->assertOk()->json('data');
    expect(json_encode($list))->not->toContain('Nota interna')->not->toContain('Admin Interno');
});

it('o Super Admin continua a ver o histórico completo do pedido', function () {
    $customer = User::factory()->create(['role' => UserRole::USER]);
    $order = minimalDataTestOrder($customer);
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => null]));

    $data = $this->getJson("/api/v1/orders/reference/{$order->order_reference}")->assertOk()->json('data');

    expect($data)->toHaveKeys(['events', 'priority', 'price_set_at']);
    expect(collect($data['events'])->pluck('notes')->all())->toContain('Nota interna da equipa: cliente difícil');
});

it('um utilizador serializado "em bruto" nunca leva campos de segurança, localização nem permissões internas', function () {
    $user = User::factory()->create([
        'latitude' => -14.9, 'longitude' => 13.5, 'failed_login_attempts' => 3, 'locked_until' => now()->addHour(),
        'last_login_at' => now(), 'notification_preferences' => ['promos' => false], 'can_promote_users' => true,
    ]);

    $raw = json_decode(json_encode($user), true);

    foreach (['password', 'remember_token', 'failed_login_attempts', 'locked_until', 'last_login_at', 'latitude', 'longitude',
        'notification_preferences', 'deletion_scheduled_for', 'avatar_path', 'sms_daily_limit', 'can_promote_users'] as $key) {
        expect($raw)->not->toHaveKey($key);
    }
    expect($raw)->toHaveKeys(['id', 'name']);
});

it('o próprio perfil (UserResource) continua completo para o dono', function () {
    $user = User::factory()->create(['latitude' => -14.9, 'longitude' => 13.5]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/users/me')->assertOk()
        ->assertJsonPath('data.latitude', -14.9)
        ->assertJsonPath('data.longitude', 13.5)
        ->assertJsonStructure(['data' => ['can_promote_users', 'deletion_scheduled_for', 'avatar_url', 'sms_daily_limit']]);
});

it('histórico de SMS: do autor do envio só segue o nome', function () {
    $super = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => null]);
    $author = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Admin Autor', 'failed_login_attempts' => 2]);
    SmsDispatch::create(['provider' => 'mock', 'recipient' => '+244923000000', 'message' => 'Olá', 'status' => 'SENT', 'created_by' => $author->id]);
    Sanctum::actingAs($super);

    $creator = $this->getJson('/api/v1/admin/sms/history')->assertOk()->json('data.0.creator');

    expect($creator)->toBe(['id' => $author->id, 'name' => 'Admin Autor']);
});
