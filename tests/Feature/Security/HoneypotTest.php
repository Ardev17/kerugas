<?php

use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Honeypot anti-bot (Outubro 2026): registo, recuperação de senha e
 * pedido de gás sem conta. Uma pessoa nunca vê o campo `website`; um bot
 * que o preenche é travado sem saber porquê.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
});

function honeypotTestRegisterPayload($test, array $extra = []): array
{
    return array_merge([
        'name' => 'Ana Silva', 'phone' => '923456789',
        'password' => 'Senha@123', 'password_confirmation' => 'Senha@123',
        'province_id' => $test->province->id, 'municipality_id' => $test->municipality->id,
    ], $extra);
}

function honeypotTestOrderPayload($test, array $extra = []): array
{
    return array_merge([
        'customer_name' => 'João Manuel', 'customer_phone' => '923456789',
        'province_id' => $test->province->id, 'municipality_id' => $test->municipality->id,
        'location_source' => 'MANUAL', 'address' => 'Rua Principal, 123', 'quantity' => 1,
    ], $extra);
}

it('registo: um bot que preenche o campo escondido não cria conta e fica no log', function () {
    Log::spy();

    $this->postJson('/api/v1/auth/register', honeypotTestRegisterPayload($this, ['website' => 'http://spam.example']))
        ->assertStatus(422)
        ->assertJsonPath('errors.website.0', 'Não foi possível validar o formulário.');

    expect(User::count())->toBe(0);
    Log::shouldHaveReceived('notice')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'honeypot') && $ctx['path'] === 'api/v1/auth/register')->once();
});

it('registo: pessoas reais (campo vazio ou ausente, ex.: app antigo) entram normalmente', function () {
    $this->postJson('/api/v1/auth/register', honeypotTestRegisterPayload($this, ['website' => '']))->assertCreated();
    $this->postJson('/api/v1/auth/register', honeypotTestRegisterPayload($this, ['phone' => '923456780']))->assertCreated();

    expect(User::count())->toBe(2);
});

it('recuperação: o bot recebe a resposta de sempre, mas nenhum email nem código é criado', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ana@exemplo.ao']);

    $bot = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@exemplo.ao', 'website' => 'x'])->assertOk();
    $human = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ninguem@exemplo.ao'])->assertOk();

    // indistinguível de um pedido normal
    expect($bot->json())->toBe($human->json());
    Notification::assertNothingSent();
    expect(DB::table('password_reset_codes')->count())->toBe(0);
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('recuperação: sem o campo, o email continua a sair', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ana@exemplo.ao']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@exemplo.ao', 'website' => ''])->assertOk();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('pedido de gás sem conta: o bot não cria pedido (nenhum condutor sai em vão)', function () {
    $this->postJson('/api/v1/orders', honeypotTestOrderPayload($this, ['website' => 'spam']))->assertStatus(422);
    expect(GasOrder::count())->toBe(0);

    $this->postJson('/api/v1/orders', honeypotTestOrderPayload($this, ['website' => '']))->assertCreated();
    expect(GasOrder::count())->toBe(1);
});
