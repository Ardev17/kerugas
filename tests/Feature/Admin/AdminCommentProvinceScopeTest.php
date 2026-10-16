<?php

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Âmbito por província para moderação de comentários (pedido explícito
 * do cliente, Setembro 2026): um ADMIN só vê e só consegue moderar
 * comentários de postos da sua própria província; só o SUPER_ADMIN vê
 * e modera tudo. A província vem sempre do posto comentado
 * (`station.province_id`), pois `station_comments` não tem coluna
 * própria de província.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huambo = Province::factory()->create(['name' => 'Huambo']);

    $this->stationBenguela = Station::factory()->create([
        'province_id' => $this->benguela->id,
        'municipality_id' => Municipality::factory()->create(['province_id' => $this->benguela->id])->id,
    ]);

    $this->stationHuambo = Station::factory()->create([
        'province_id' => $this->huambo->id,
        'municipality_id' => Municipality::factory()->create(['province_id' => $this->huambo->id])->id,
    ]);

    $this->commentBenguela = StationComment::factory()->create(['station_id' => $this->stationBenguela->id]);
    $this->commentHuambo = StationComment::factory()->create(['station_id' => $this->stationHuambo->id]);

    $this->adminBenguela = User::factory()->admin()->create(['province_id' => $this->benguela->id]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->benguela->id]);
});

it('um ADMIN só vê, na listagem, comentários de postos da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->getJson('/api/v1/admin/comments?status=PENDING');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->commentBenguela->id)
        ->and($ids)->not->toContain($this->commentHuambo->id);
});

it('o SUPER_ADMIN vê comentários de postos de todas as províncias', function () {
    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/comments?status=PENDING');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->commentBenguela->id)
        ->and($ids)->toContain($this->commentHuambo->id);
});

it('um ADMIN não consegue moderar um comentário de um posto de outra província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->patchJson("/api/v1/admin/comments/{$this->commentHuambo->id}/status", [
        'status' => 'VISIBLE',
    ]);

    $response->assertStatus(403);
    expect($this->commentHuambo->fresh()->status->value)->toBe('PENDING');
});

it('um ADMIN consegue moderar normalmente um comentário de um posto da sua própria província', function () {
    Sanctum::actingAs($this->adminBenguela);

    $response = $this->patchJson("/api/v1/admin/comments/{$this->commentBenguela->id}/status", [
        'status' => 'VISIBLE',
    ]);

    $response->assertOk();
    expect($this->commentBenguela->fresh()->status->value)->toBe('VISIBLE');
});

it('cada comentário da moderação traz o posto a que foi feito', function () {
    Sanctum::actingAs($this->superAdmin);

    $comment = collect($this->getJson('/api/v1/admin/comments?status=PENDING')->assertOk()->json('data'))
        ->firstWhere('id', $this->commentBenguela->id);

    expect($comment['station']['id'])->toBe($this->stationBenguela->id)
        ->and($comment['station']['name'])->toBe($this->stationBenguela->name)
        ->and($comment['station']['province'])->toBe('Benguela');
});
