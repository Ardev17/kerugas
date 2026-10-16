<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Saldo de créditos do provedor de SMS (pedido explícito do cliente,
 * Setembro 2026) — estritamente SUPER_ADMIN, mesmo `permission:sms.view`
 * deixando ADMIN entrar na rota (ver comentário em
 * `SmsDispatchController::credits()`).
 */
beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->admin = User::factory()->admin()->create();
});

it('um ADMIN comum não consegue consultar o saldo de créditos, mesmo tendo sms.view', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/admin/sms/credits');

    $response->assertStatus(403);
});

it('o Super Admin recebe supported=false quando o provedor ativo não é a Kamba (ex: mock)', function () {
    config(['kerugas.sms.provider' => 'mock']);

    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/sms/credits');

    $response->assertOk();
    $response->assertJsonPath('data.supported', false);
    $response->assertJsonPath('data.balance', null);
});

it('o Super Admin consegue consultar o saldo de créditos quando SMS_PROVIDER=kamba', function () {
    config(['kerugas.sms.provider' => 'kamba', 'kerugas.sms.api_key' => 'fake-api-key']);
    Http::fake(['*/credits/balance' => Http::response(['balance' => 128], 200)]);

    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/sms/credits');

    $response->assertOk();
    $response->assertJsonPath('data.supported', true);
    $response->assertJsonPath('data.provider', 'kamba');
    $response->assertJsonPath('data.balance', 128);
});

it('devolve balance=null com mensagem quando a consulta à Kamba falha', function () {
    config(['kerugas.sms.provider' => 'kamba', 'kerugas.sms.api_key' => 'fake-api-key']);
    Http::fake(['*/credits/balance' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/sms/credits');

    $response->assertOk();
    $response->assertJsonPath('data.supported', true);
    $response->assertJsonPath('data.balance', null);
    expect($response->json('data.message'))->not->toBeNull();
});
