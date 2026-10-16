<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Faturação por entrega (pedido explícito do cliente, Setembro 2026):
 * o preço é sempre negociado por telefone e introduzido manualmente
 * pelo Admin ao confirmar o pedido — nunca calculado automaticamente.
 * Um ADMIN só vê os valores que ele próprio registou; o SUPER_ADMIN vê
 * tudo, incluindo o detalhe por Admin.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);

    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->province->id]);

    $this->order = GasOrder::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'status' => 'CONTACTING',
    ]);
});

it('recusa confirmar um pedido sem indicar o preço de entrega', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm");

    $response->assertStatus(422)->assertJsonValidationErrors('delivery_price');
    expect($this->order->fresh())
        ->status->value->toBe('CONTACTING')
        ->delivery_price->toBeNull();
});

it('recusa um preço de entrega negativo', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", ['delivery_price' => -50]);

    $response->assertStatus(422)->assertJsonValidationErrors('delivery_price');
});

it('permite confirmar um pedido com o preço de entrega acertado com o cliente', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", ['delivery_price' => 3500, 'cylinder_types_confirmed' => 'P13']);

    $response->assertOk();
    expect($this->order->fresh())
        ->status->value->toBe('CONFIRMED')
        ->delivery_price->toEqual(3500)
        ->price_set_by->toBe($this->admin->id)
        ->price_set_at->not->toBeNull();
});

it('permite uma entrega gratuita (preço 0) como caso de negócio válido', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson("/api/v1/admin/orders/{$this->order->id}/confirm", ['delivery_price' => 0, 'cylinder_types_confirmed' => 'P13']);

    $response->assertOk();
    expect($this->order->fresh())->delivery_price->toEqual(0);
});

it('recusa a um ADMIN comum corrigir um preço já registado', function () {
    $this->order->update(['delivery_price' => 3500, 'price_set_by' => $this->admin->id, 'price_set_at' => now(), 'status' => 'CONFIRMED']);
    Sanctum::actingAs($this->admin);

    $response = $this->patchJson("/api/v1/admin/orders/{$this->order->id}/price", ['delivery_price' => 4000]);

    $response->assertStatus(403);
    expect($this->order->fresh())->delivery_price->toEqual(3500);
});

it('permite ao SUPER_ADMIN corrigir um preço já registado e regista auditoria', function () {
    $this->order->update(['delivery_price' => 3500, 'price_set_by' => $this->admin->id, 'price_set_at' => now(), 'status' => 'CONFIRMED']);
    Sanctum::actingAs($this->superAdmin);

    $response = $this->patchJson("/api/v1/admin/orders/{$this->order->id}/price", ['delivery_price' => 4200]);

    $response->assertOk();
    expect($this->order->fresh())
        ->delivery_price->toEqual(4200)
        ->price_set_by->toBe($this->admin->id) // nunca sobrescrito pela correção
        ->price_corrected_by->toBe($this->superAdmin->id)
        ->price_corrected_at->not->toBeNull();

    $log = AuditLog::where('action', 'order.price_corrected')->first();
    expect($log)->not->toBeNull()
        // JSON não distingue 3500 de 3500.0 (o PHP escreve "3500"), por isso toEqual.
        ->and($log->old_values)->toEqual(['delivery_price' => 3500])
        ->and($log->new_values)->toEqual(['delivery_price' => 4200]);
});

it('recusa corrigir o preço de um pedido que ainda não tem preço registado', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->patchJson("/api/v1/admin/orders/{$this->order->id}/price", ['delivery_price' => 4200]);

    $response->assertStatus(422);
});

it('um ADMIN só vê, na Faturação, os valores que ele próprio registou', function () {
    $outroAdmin = User::factory()->admin()->create(['province_id' => $this->province->id]);

    GasOrder::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id,
        'status' => 'CONFIRMED', 'delivery_price' => 1000, 'price_set_by' => $this->admin->id, 'price_set_at' => now(),
    ]);
    GasOrder::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id,
        'status' => 'CONFIRMED', 'delivery_price' => 5000, 'price_set_by' => $outroAdmin->id, 'price_set_at' => now(),
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/admin/billing');

    $response->assertOk();
    expect($response->json('data.scope'))->toBe('own')
        ->and($response->json('data.summary.all_time_total'))->toEqual(1000)
        ->and($response->json('data.summary.all_time_count'))->toBe(1)
        ->and($response->json('data.by_admin'))->toBeNull();
});

it('o SUPER_ADMIN vê a faturação de todos os Admins, com detalhe por Admin', function () {
    $outroAdmin = User::factory()->admin()->create(['province_id' => $this->province->id, 'name' => 'Segundo Admin']);

    GasOrder::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id,
        'status' => 'CONFIRMED', 'delivery_price' => 1000, 'price_set_by' => $this->admin->id, 'price_set_at' => now(),
    ]);
    GasOrder::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id,
        'status' => 'CONFIRMED', 'delivery_price' => 5000, 'price_set_by' => $outroAdmin->id, 'price_set_at' => now(),
    ]);

    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/billing');

    $response->assertOk();
    expect($response->json('data.scope'))->toBe('all')
        ->and($response->json('data.summary.all_time_total'))->toEqual(6000)
        ->and($response->json('data.summary.all_time_count'))->toBe(2);

    $byAdmin = collect($response->json('data.by_admin'))->keyBy('admin_id');
    expect($byAdmin[$this->admin->id]['total'])->toEqual(1000)
        ->and($byAdmin[$outroAdmin->id]['total'])->toEqual(5000);
});

it('um pedido ainda em negociação (sem preço) nunca entra nos totais de Faturação', function () {
    // $this->order está em CONTACTING, sem delivery_price.
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/admin/billing');

    $response->assertOk();
    expect($response->json('data.summary.all_time_count'))->toBe(0);
});
