<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** "Meu Perfil" (Outubro 2026): cada utilizador edita só os seus dados. */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->other = Province::factory()->create();
    $this->otherMun = Municipality::factory()->create(['province_id' => $this->other->id]);

    $this->user = User::factory()->create([
        'name' => 'Ana Silva', 'phone' => '+244923111000', 'email' => 'ana@exemplo.ao',
        'password' => Hash::make('Segredo123'), 'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id,
    ]);
});

it('edita nome, localização e localização exata (sem pedir a palavra-passe)', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', [
        'name' => '  Ana   Maria Silva ',
        'province_id' => $this->other->id, 'municipality_id' => $this->otherMun->id,
        'latitude' => -14.9, 'longitude' => 13.5,
    ])->assertOk()->assertJsonPath('data.name', 'Ana Maria Silva')->assertJsonPath('data.province_id', $this->other->id);

    expect(AuditLog::where('action', 'profile.updated')->first()->new_values)->toHaveKey('name');

    // remover a localização exata
    $this->putJson('/api/v1/users/me', ['latitude' => null, 'longitude' => null])->assertOk()->assertJsonPath('data.latitude', null);
});

it('nunca deixa mudar papel, estado ou permissões por esta via', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', [
        'name' => 'Ana', 'role' => 'SUPER_ADMIN', 'status' => 'ACTIVE', 'can_promote_users' => true, 'sms_daily_limit' => 999, 'driver_available' => false,
    ])->assertOk();

    expect($this->user->fresh())->role->toBe(UserRole::USER)->can_promote_users->toBeFalse()->sms_daily_limit->toBeNull();
});

it('mudar telemóvel ou email exige a palavra-passe atual', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', ['phone' => '923 222 333'])->assertStatus(422)->assertJsonValidationErrors('current_password');
    $this->putJson('/api/v1/users/me', ['email' => 'nova@exemplo.ao', 'current_password' => 'errada'])->assertStatus(422)->assertJsonValidationErrors('current_password');

    $this->putJson('/api/v1/users/me', ['phone' => '923 222 333', 'email' => 'nova@exemplo.ao', 'current_password' => 'Segredo123'])
        ->assertOk()->assertJsonPath('data.phone', '+244923222333')->assertJsonPath('data.email', 'nova@exemplo.ao');

    expect(AppNotification::where('user_id', $this->user->id)->where('title', 'Dados de acesso alterados')->count())->toBe(1);
    // reenviar o mesmo telemóvel (noutro formato) não conta como mudança
    $this->putJson('/api/v1/users/me', ['phone' => '+244 923 222 333'])->assertOk();
});

it('recusa telemóvel inválido ou de outra conta, e email repetido', function () {
    User::factory()->create(['phone' => '+244923999888', 'email' => 'outro@exemplo.ao']);
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', ['phone' => '12345', 'current_password' => 'Segredo123'])->assertStatus(422)->assertJsonValidationErrors('phone');
    $this->putJson('/api/v1/users/me', ['phone' => '923999888', 'current_password' => 'Segredo123'])->assertStatus(422)->assertJsonValidationErrors('phone');
    $this->putJson('/api/v1/users/me', ['email' => 'outro@exemplo.ao', 'current_password' => 'Segredo123'])->assertStatus(422)->assertJsonValidationErrors('email');
    $this->putJson('/api/v1/users/me', ['name' => '<script>'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->putJson('/api/v1/users/me', ['name' => ''])->assertStatus(422);
});

it('o município tem de pertencer à província', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', ['province_id' => $this->province->id, 'municipality_id' => $this->otherMun->id])
        ->assertStatus(422)->assertJsonValidationErrors('municipality_id');
});

it('Admin, Super Admin Provincial e Condutor não mudam a própria área de trabalho, mas editam o resto', function () {
    foreach ([UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::DRIVER] as $i => $role) {
        $u = User::factory()->create(['role' => $role, 'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'phone' => "+24492300010{$i}"]);
        Sanctum::actingAs($u);

        $this->putJson('/api/v1/users/me', ['province_id' => $this->other->id, 'municipality_id' => $this->otherMun->id])
            ->assertStatus(422)->assertJsonValidationErrors('province_id');
        $this->putJson('/api/v1/users/me', ['name' => 'Nome Novo', 'province_id' => $this->province->id])->assertOk();
        expect($u->fresh())->province_id->toBe($this->province->id)->name->toBe('Nome Novo');
    }
});

it('qualquer papel tem perfil (Super Admin, operador, moderador)', function () {
    foreach ([UserRole::SUPER_ADMIN, UserRole::STATION_OPERATOR, UserRole::MODERATOR] as $i => $role) {
        $u = User::factory()->create(['role' => $role, 'phone' => "+24492300020{$i}"]);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.role', $role->value);
        $this->putJson('/api/v1/users/me', ['name' => 'Editado'])->assertOk()->assertJsonPath('data.name', 'Editado');
    }
});

it('muda a palavra-passe, termina as outras sessões e mantém esta', function () {
    $current = $this->user->createToken('este');
    $other = $this->user->createToken('outro-telemovel');
    DeviceToken::create(['user_id' => $this->user->id, 'personal_access_token_id' => $other->accessToken->id, 'token' => 'fcm-outro', 'platform' => 'android']);

    $this->withHeader('Authorization', "Bearer {$current->plainTextToken}")
        ->putJson('/api/v1/users/me/password', ['current_password' => 'Segredo123', 'password' => 'NovaSenha99', 'password_confirmation' => 'NovaSenha99'])
        ->assertOk()->assertJsonPath('data.other_sessions_ended', 1);

    expect(Hash::check('NovaSenha99', $this->user->fresh()->password))->toBeTrue()
        ->and($this->user->tokens()->pluck('name')->all())->toBe(['este'])
        ->and(DeviceToken::count())->toBe(0)
        ->and(AuditLog::where('action', 'profile.password_changed')->count())->toBe(1)
        ->and(AppNotification::where('user_id', $this->user->id)->where('title', 'Palavra-passe alterada')->count())->toBe(1);
});

it('valida a palavra-passe: atual certa, forte, confirmada e diferente', function () {
    Sanctum::actingAs($this->user);
    $url = '/api/v1/users/me/password';

    $this->putJson($url, ['current_password' => 'errada', 'password' => 'NovaSenha99', 'password_confirmation' => 'NovaSenha99'])
        ->assertStatus(422)->assertJsonValidationErrors('current_password');
    $this->putJson($url, ['current_password' => 'Segredo123', 'password' => 'curta1', 'password_confirmation' => 'curta1'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->putJson($url, ['current_password' => 'Segredo123', 'password' => 'sóletrasaqui', 'password_confirmation' => 'sóletrasaqui'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->putJson($url, ['current_password' => 'Segredo123', 'password' => 'NovaSenha99', 'password_confirmation' => 'Outra999'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->putJson($url, ['current_password' => 'Segredo123', 'password' => 'Segredo123', 'password_confirmation' => 'Segredo123'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    expect(Hash::check('Segredo123', $this->user->fresh()->password))->toBeTrue();
});

it('lista as sessões sem expor tokens e termina as outras', function () {
    $current = $this->user->createToken('este');
    $this->user->createToken('outro');
    $auth = ['Authorization' => "Bearer {$current->plainTextToken}"];

    $list = $this->withHeaders($auth)->getJson('/api/v1/users/me/sessions')->assertOk()->json('data');
    expect($list)->toHaveCount(2)
        ->and(collect($list)->firstWhere('current', true)['name'])->toBe('este')
        ->and(json_encode($list))->not->toContain($current->plainTextToken);

    $this->withHeaders($auth)->deleteJson('/api/v1/users/me/sessions/others')->assertOk()->assertJsonPath('data.other_sessions_ended', 1);
    expect($this->user->tokens()->count())->toBe(1);
});

it('sem sessão ou com conta suspensa não há perfil', function () {
    $this->putJson('/api/v1/users/me', ['name' => 'X'])->assertUnauthorized();

    $this->user->update(['status' => 'SUSPENDED']);
    Sanctum::actingAs($this->user);
    $this->putJson('/api/v1/users/me', ['name' => 'X'])->assertForbidden();
});

it('nunca grava latitude sem longitude', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', ['latitude' => -14.9])->assertStatus(422)->assertJsonValidationErrors('longitude');
    $this->putJson('/api/v1/users/me', ['latitude' => null, 'longitude' => 13.5])->assertStatus(422);
    expect($this->user->fresh()->latitude)->toBeNull();
});

it('5 palavras-passe erradas bloqueiam a confirmação durante 15 minutos', function () {
    Sanctum::actingAs($this->user);

    foreach (range(1, 5) as $i) {
        $this->putJson('/api/v1/users/me/password', ['current_password' => "errada{$i}", 'password' => 'NovaSenha99', 'password_confirmation' => 'NovaSenha99'])
            ->assertStatus(422);
    }

    // mesmo a certa é recusada enquanto dura o bloqueio — e no outro formulário também
    $this->putJson('/api/v1/users/me/password', ['current_password' => 'Segredo123', 'password' => 'NovaSenha99', 'password_confirmation' => 'NovaSenha99'])
        ->assertStatus(422)->assertJsonPath('errors.current_password.0', fn ($m) => str_contains($m, 'Demasiadas tentativas'));
    $this->putJson('/api/v1/users/me', ['email' => 'x@exemplo.ao', 'current_password' => 'Segredo123'])->assertStatus(422);

    $this->travel(16)->minutes();
    $this->putJson('/api/v1/users/me/password', ['current_password' => 'Segredo123', 'password' => 'NovaSenha99', 'password_confirmation' => 'NovaSenha99'])->assertOk();
});

it('dados antigos inconsistentes não impedem de editar o nome', function () {
    $legacy = User::factory()->create(['phone' => '+244923000300', 'province_id' => $this->province->id, 'municipality_id' => $this->otherMun->id]);
    Sanctum::actingAs($legacy);

    $this->putJson('/api/v1/users/me', ['name' => 'Corrigido'])->assertOk();
});
