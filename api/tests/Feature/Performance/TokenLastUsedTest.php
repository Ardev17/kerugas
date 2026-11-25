<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
 * Velocidade (Outubro 2026): o token só volta a gravar `last_used_at`
 * quando o valor guardado tem mais de 5 minutos.
 */

afterEach(fn () => Carbon::setTestNow());

it('usa o modelo de token do KeruGás e não regrava last_used_at a cada pedido', function () {
    Carbon::setTestNow('2026-10-08 10:00:00');
    $user = User::factory()->create();
    $plain = $user->createToken('telemovel')->plainTextToken;
    $token = PersonalAccessToken::findToken($plain);
    expect($token)->toBeInstanceOf(PersonalAccessToken::class);

    $this->withToken($plain)->getJson('/api/v1/users/me')->assertOk();
    expect($token->fresh()->last_used_at->toDateTimeString())->toBe('2026-10-08 10:00:00');

    Carbon::setTestNow('2026-10-08 10:03:00');
    $this->app['auth']->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/users/me')->assertOk();
    expect($token->fresh()->last_used_at->toDateTimeString())->toBe('2026-10-08 10:00:00');

    Carbon::setTestNow('2026-10-08 10:06:00');
    $this->app['auth']->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/users/me')->assertOk();
    expect($token->fresh()->last_used_at->toDateTimeString())->toBe('2026-10-08 10:06:00');
});

it('continua a gravar outras alterações do token normalmente', function () {
    $user = User::factory()->create();
    $token = PersonalAccessToken::findToken($user->createToken('pc')->plainTextToken);
    $token->forceFill(['last_used_at' => now()])->save();

    $token->forceFill(['name' => 'portátil', 'last_used_at' => now()])->save();

    expect($token->fresh()->name)->toBe('portátil');
});
