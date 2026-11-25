<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/**
 * Recuperar a palavra-passe por email (Outubro 2026): código de 6 dígitos
 * para a app + link para o site; no fim todas as sessões terminam.
 */
function resetCodeTestRequestCode($test, User $user): string
{
    Notification::fake();
    $test->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    $code = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$code) {
        $code = $n->code;

        return true;
    });

    return (string) $code;
}

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ana@exemplo.ao', 'password' => Hash::make('Antiga123')]);
});

it('envia um email com código de 6 dígitos e o link', function () {
    $code = resetCodeTestRequestCode($this, $this->user);

    expect($code)->toMatch('/^\d{6}$/');
    expect(DB::table('password_reset_codes')->where('email', 'ana@exemplo.ao')->value('code_hash'))->not->toBe($code); // só o hash
    expect(DB::table('password_reset_tokens')->where('email', 'ana@exemplo.ao')->exists())->toBeTrue(); // o link continua
});

it('com o código certo repõe a palavra-passe, termina todas as sessões e não deixa reutilizar o código', function () {
    $this->user->createToken('telemovel');
    $code = resetCodeTestRequestCode($this, $this->user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ANA@exemplo.ao', 'code' => $code, 'password' => 'Nova12345', 'password_confirmation' => 'Nova12345',
    ])->assertOk();

    $user = $this->user->fresh();
    expect(Hash::check('Nova12345', $user->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    expect(AuditLog::where('action', 'auth.password_reset')->where('entity_id', $user->id)->exists())->toBeTrue();
    expect(DB::table('password_reset_tokens')->where('email', 'ana@exemplo.ao')->exists())->toBeFalse(); // link anulado também

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ana@exemplo.ao', 'code' => $code, 'password' => 'Outra12345', 'password_confirmation' => 'Outra12345',
    ])->assertStatus(422)->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/auth/login', ['identifier' => 'ana@exemplo.ao', 'password' => 'Nova12345'])->assertOk();
});

it('5 tentativas erradas anulam o código (mesmo que depois venha o certo)', function () {
    $code = resetCodeTestRequestCode($this, $this->user);
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'ana@exemplo.ao', 'code' => $wrong, 'password' => 'Nova12345', 'password_confirmation' => 'Nova12345',
        ])->assertStatus(422);
    }

    // limpa só o limite de pedidos por minuto (throttle:auth), para provar
    // que é o CÓDIGO que ficou anulado
    \Illuminate\Support\Facades\Cache::flush();
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ana@exemplo.ao', 'code' => $code, 'password' => 'Nova12345', 'password_confirmation' => 'Nova12345',
    ])->assertStatus(422);

    expect(Hash::check('Antiga123', $this->user->fresh()->password))->toBeTrue();
});

it('recusa um código expirado', function () {
    $code = resetCodeTestRequestCode($this, $this->user);
    DB::table('password_reset_codes')->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ana@exemplo.ao', 'code' => $code, 'password' => 'Nova12345', 'password_confirmation' => 'Nova12345',
    ])->assertStatus(422);
});

it('não revela se o email existe', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ninguem@exemplo.ao'])
        ->assertOk()->assertJsonPath('success', true);

    Notification::assertNothingSent();
});

it('o link do email (site) continua a funcionar', function () {
    $token = Password::createToken($this->user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ana@exemplo.ao', 'token' => $token, 'password' => 'Nova12345', 'password_confirmation' => 'Nova12345',
    ])->assertOk();

    expect(Hash::check('Nova12345', $this->user->fresh()->password))->toBeTrue();
});

it('exige código ou link e uma palavra-passe com letras e números', function () {
    $this->postJson('/api/v1/auth/reset-password', ['email' => 'ana@exemplo.ao', 'password' => 'Nova12345', 'password_confirmation' => 'Nova12345'])
        ->assertStatus(422)->assertJsonValidationErrors(['code', 'token']);

    $this->postJson('/api/v1/auth/reset-password', ['email' => 'ana@exemplo.ao', 'code' => '123456', 'password' => '12345678', 'password_confirmation' => '12345678'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});
