<?php

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Outubro 2026: promover um utilizador a Moderador/Operador deixa de ser
 * automático para todos os Admins. O Super Admin liga ou desliga a
 * permissão (`can_promote_users`) Admin a Admin; por omissão está desligada.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => $this->province->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->target = User::factory()->create(['province_id' => $this->province->id]);
});

it('por omissão um Admin não consegue promover um utilizador', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['role' => 'MODERATOR'])->assertForbidden();
    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['role' => 'STATION_OPERATOR'])->assertForbidden();

    expect($this->target->fresh()->role)->toBe(UserRole::USER);
});

it('com a permissão ativada, o Admin promove a Moderador e a Operador na sua província', function () {
    $this->admin->update(['can_promote_users' => true]);
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['role' => 'MODERATOR'])->assertOk();
    expect($this->target->fresh()->role)->toBe(UserRole::MODERATOR);

    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['role' => 'STATION_OPERATOR'])->assertOk();
    expect($this->target->fresh()->role)->toBe(UserRole::STATION_OPERATOR);
});

it('mesmo com a permissão, o Admin continua sem poder promover a Admin/Super Admin/Condutor', function () {
    $this->admin->update(['can_promote_users' => true]);
    Sanctum::actingAs($this->admin);

    foreach (['ADMIN', 'SUPER_ADMIN', 'DRIVER'] as $role) {
        $this->putJson("/api/v1/admin/users/{$this->target->id}", ['role' => $role])->assertForbidden();
    }
});

it('bloquear e desbloquear continua permitido sem a permissão de promover', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['status' => 'SUSPENDED'])->assertOk();
});

it('reenviar o papel que a conta já tem não conta como promoção', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['role' => 'USER', 'status' => 'SUSPENDED'])->assertOk();
});

it('sem a permissão, o Admin também não cria contas de Moderador/Operador, mas cria contas comuns', function () {
    Sanctum::actingAs($this->admin);
    $base = ['password' => 'password123', 'province_id' => $this->province->id];

    $this->postJson('/api/v1/admin/users', $base + ['name' => 'Mod', 'phone' => '+244900000201', 'role' => 'MODERATOR'])->assertForbidden();
    $this->postJson('/api/v1/admin/users', $base + ['name' => 'Op', 'phone' => '+244900000202', 'role' => 'STATION_OPERATOR'])->assertForbidden();
    $this->postJson('/api/v1/admin/users', $base + ['name' => 'Normal', 'phone' => '+244900000203', 'role' => 'USER'])->assertCreated();
});

it('o Super Admin liga e desliga a permissão de um Admin', function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson("/api/v1/admin/users/{$this->admin->id}", ['can_promote_users' => true])
        ->assertOk()->assertJsonPath('data.can_promote_users', true);
    expect($this->admin->fresh()->can_promote_users)->toBeTrue();

    $this->putJson("/api/v1/admin/users/{$this->admin->id}", ['can_promote_users' => false])
        ->assertOk()->assertJsonPath('data.can_promote_users', false);
    expect($this->admin->fresh()->can_promote_users)->toBeFalse();
});

it('um Admin nunca concede a permissão — nem a si próprio nem a um colega', function () {
    $colleague = User::factory()->admin()->create(['province_id' => $this->province->id]);
    Sanctum::actingAs($this->admin);

    // 422 com a mensagem no campo — o mesmo padrão das outras regras "só o Super Admin" (província, limite de SMS).
    $this->putJson("/api/v1/admin/users/{$colleague->id}", ['can_promote_users' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['can_promote_users']);
    expect($colleague->fresh()->can_promote_users)->toBeFalse();
});

it('a permissão só se aplica a contas de Admin', function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson("/api/v1/admin/users/{$this->target->id}", ['can_promote_users' => true])
        ->assertStatus(422)->assertJsonValidationErrors('can_promote_users');
});

it('um Admin despromovido perde a permissão', function () {
    $this->admin->update(['can_promote_users' => true]);
    Sanctum::actingAs($this->superAdmin);

    $this->putJson("/api/v1/admin/users/{$this->admin->id}", ['role' => 'USER'])->assertOk();
    expect($this->admin->fresh()->can_promote_users)->toBeFalse();
});

it('o /me do Admin indica se tem a permissão (o ecrã usa-o para desativar o seletor)', function () {
    $this->admin->update(['can_promote_users' => true]);
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.can_promote_users', true);
});
