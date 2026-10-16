<?php

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Registo de telemóveis para push (app Android, Setembro 2026). Usa
 * tokens Bearer reais (createToken), como o app — é assim que se testa
 * a ligação telemóvel ↔ sessão que o logout depois desfaz.
 */
it('regista o telemóvel na conta e na sessão com que fez login', function () {
    $user = User::factory()->create();
    $session = $user->createToken('app');

    $this->withHeader('Authorization', "Bearer {$session->plainTextToken}")
        ->postJson('/api/v1/devices', ['token' => 'fcm-token-A', 'platform' => 'android', 'app_version' => '1.0.0'])
        ->assertCreated();

    $device = DeviceToken::first();
    expect($device->user_id)->toBe($user->id)
        ->and($device->personal_access_token_id)->toBe($session->accessToken->id)
        ->and($device->app_version)->toBe('1.0.0');
});

it('um telemóvel partilhado passa para a conta que fez login mais recentemente', function () {
    $operatorMorning = User::factory()->create();
    $operatorEvening = User::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . $operatorMorning->createToken('app')->plainTextToken)
        ->postJson('/api/v1/devices', ['token' => 'shared-phone'])->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', 'Bearer ' . $operatorEvening->createToken('app')->plainTextToken)
        ->postJson('/api/v1/devices', ['token' => 'shared-phone'])->assertCreated();

    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::first()->user_id)->toBe($operatorEvening->id);
});

it('ao terminar sessão, o telemóvel dessa sessão deixa de receber push', function () {
    $user = User::factory()->create();
    $token = $user->createToken('app')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/devices', ['token' => 'fcm-token-B'])->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')->assertOk();

    expect(DeviceToken::count())->toBe(0);
});

it('ninguém consegue remover o telemóvel de outra pessoa', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    DeviceToken::create(['user_id' => $owner->id, 'token' => 'victim-token']);

    $this->withHeader('Authorization', 'Bearer ' . $attacker->createToken('app')->plainTextToken)
        ->deleteJson('/api/v1/devices', ['token' => 'victim-token'])
        ->assertOk();

    expect(DeviceToken::where('token', 'victim-token')->exists())->toBeTrue();
});

it('exige sessão para registar um telemóvel', function () {
    $this->postJson('/api/v1/devices', ['token' => 'x'])->assertUnauthorized();
});

it('regista um iPhone (token da Apple) e recusa sistemas desconhecidos', function () {
    $user = User::factory()->create();
    $this->withHeader('Authorization', 'Bearer ' . $user->createToken('app')->plainTextToken);

    $this->postJson('/api/v1/devices', ['token' => str_repeat('ab', 32), 'platform' => 'ios', 'app_version' => '1.0.0'])->assertCreated();
    expect(DeviceToken::first()->platform)->toBe('ios');

    $this->postJson('/api/v1/devices', ['token' => 'x', 'platform' => 'windows'])->assertUnprocessable();
});
