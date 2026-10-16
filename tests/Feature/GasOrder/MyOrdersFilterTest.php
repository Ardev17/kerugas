<?php

use App\Models\GasOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    foreach (['PENDING', 'CONFIRMED', 'DELIVERED', 'CANCELLED', 'FAILED'] as $status) {
        GasOrder::factory()->create(['user_id' => $this->user->id, 'status' => $status]);
    }
    // Pedido de outra conta: nunca aparece.
    GasOrder::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'CANCELLED']);
    Sanctum::actingAs($this->user);
});

function statusesFor(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/orders' . $query)->assertOk()->json('data'))->pluck('status')->sort()->values()->all();
}

it('sem filtro devolve todos os pedidos da conta', function () {
    expect(statusesFor())->toHaveCount(5);
});

it('filtra os pedidos em curso', function () {
    expect(statusesFor('?filter=active'))->toBe(['CONFIRMED', 'PENDING']);
});

it('filtra os entregues', function () {
    expect(statusesFor('?filter=delivered'))->toBe(['DELIVERED']);
});

it('cancelados inclui os que falharam', function () {
    expect(statusesFor('?filter=cancelled'))->toBe(['CANCELLED', 'FAILED']);
});

it('rejeita filtros desconhecidos', function () {
    $this->getJson('/api/v1/orders?filter=xpto')->assertStatus(422);
});
