<?php

use App\Models\AppNotification;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// Contadores do LoginThrottle em memória e limpos em cada teste (nunca no cache real).
beforeEach(fn () => config(['cache.default' => 'array']));

it('permite login com telefone e password corretos', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123')]);

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->phone,
        'password' => 'Senha@123',
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);
});

it('rejeita login com password errada', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123')]);

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->phone,
        'password' => 'password-errada',
    ]);

    $response->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

/*
 * Proteção contra adivinhação (Outubro 2026): os erros contam por "conta +
 * rede" (LoginThrottle). Nunca se bloqueia a conta inteira nem toda a rede.
 */
function loginFrom(string $ip, string $identifier, string $password)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password]);
}

it('5 erros na mesma conta a partir da mesma rede põem SÓ esse par em pausa', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123')]);

    for ($i = 0; $i < 5; $i++) {
        loginFrom('10.0.0.1', $user->phone, 'password-errada')->assertStatus(401);
    }

    // Da mesma rede, nem a palavra-passe certa passa por agora…
    loginFrom('10.0.0.1', $user->phone, 'Senha@123')
        ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');

    // …mas o DONO, noutra rede, entra normalmente: ninguém consegue bloquear a conta de outra pessoa.
    loginFrom('10.0.0.2', $user->phone, 'Senha@123')->assertOk();
    expect($user->fresh()->locked_until)->toBeNull();
});

it('o mesmo telefone escrito de outra forma conta como a mesma conta', function () {
    $user = User::factory()->create(['phone' => '+244923111222', 'password' => Hash::make('Senha@123')]);

    foreach (['923111222', '+244923111222', '244923111222', '923 111 222', '923111222'] as $variant) {
        loginFrom('10.0.0.3', $variant, 'errada');
    }
    loginFrom('10.0.0.3', '+244923111222', 'Senha@123')->assertStatus(429);
});

it('pessoas diferentes atrás do mesmo IP da operadora não se bloqueiam umas às outras', function () {
    $outros = User::factory()->count(10)->create(['password' => Hash::make('Outra@123')]);
    foreach ($outros as $u) {
        for ($i = 0; $i < 3; $i++) {
            loginFrom('41.0.0.9', $u->phone, 'engano');   // 30 enganos de 10 pessoas
        }
    }

    $eu = User::factory()->create(['password' => Hash::make('Senha@123')]);
    loginFrom('41.0.0.9', $eu->phone, 'Senha@123')->assertOk();
});

it('logins certos nunca contam e um login certo limpa os erros do par', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123')]);

    for ($i = 0; $i < 4; $i++) {
        loginFrom('10.0.0.4', $user->phone, 'errada');
    }
    loginFrom('10.0.0.4', $user->phone, 'Senha@123')->assertOk();

    for ($i = 0; $i < 4; $i++) {
        loginFrom('10.0.0.4', $user->phone, 'errada')->assertStatus(401); // recomeçou do zero
    }
});

it('um ataque em massa a partir de uma rede trava-a (teto alto, só erros)', function () {
    $alvos = User::factory()->count(25)->create();
    $n = 0;
    foreach ($alvos as $u) {
        for ($i = 0; $i < 4 && $n < LoginThrottle::IP_MAX; $i++, $n++) {
            loginFrom('66.6.6.6', $u->phone, 'chute-' . $i);
        }
    }

    $vitima = User::factory()->create(['password' => Hash::make('Senha@123')]);
    loginFrom('66.6.6.6', $vitima->phone, 'Senha@123')->assertStatus(429);
    loginFrom('10.0.0.5', $vitima->phone, 'Senha@123')->assertOk(); // outras redes não são afetadas
});

it('muitos erros numa conta, vindos de várias redes, avisam o dono — sem bloquear a conta', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123')]);

    for ($i = 0; $i < LoginThrottle::ACCOUNT_ALERT; $i++) {
        loginFrom('172.16.0.' . ($i + 1), $user->phone, 'errada');
    }

    expect(AppNotification::where('user_id', $user->id)->where('title', 'Tentativas de entrada na sua conta')->count())->toBe(1);
    loginFrom('10.0.0.6', $user->phone, 'Senha@123')->assertOk();
});

it('uma conta que não existe responde igual a uma palavra-passe errada', function () {
    loginFrom('10.0.0.7', '+244900999999', 'qualquer')->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('rejeita login de conta suspensa mesmo com password correta', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123'), 'status' => 'SUSPENDED']);

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->phone,
        'password' => 'Senha@123',
    ]);

    $response->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
});

it('um login certo limpa o contador antigo da conta (compatibilidade)', function () {
    $user = User::factory()->create(['password' => Hash::make('Senha@123'), 'failed_login_attempts' => 3]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => $user->phone,
        'password' => 'Senha@123',
    ])->assertOk();

    expect($user->fresh()->failed_login_attempts)->toBe(0);
});
