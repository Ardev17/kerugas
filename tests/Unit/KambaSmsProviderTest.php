<?php

use App\Services\Sms\KambaSmsProvider;
use Illuminate\Support\Facades\Http;

/**
 * KambaSMS (Setembro 2026): endpoints reais confirmados na documentação
 * completa deles (`https://www.kambasms.ao/dashboard/docs`) — base URL
 * `https://nexasms-api.onrender.com`, `POST /messages/send` com campos
 * `to`/`text` (o Sender ID é automático, ligado à API key), autenticação
 * via header `X-API-Key`. Essa mesma documentação não tem NENHUM
 * endpoint de callback/webhook — por isso `verifyWebhook()` rejeita
 * sempre, em vez de fingir uma verificação que a API nem oferece.
 */
beforeEach(function () {
    $this->provider = new KambaSmsProvider('fake-api-key', 'https://nexasms-api.onrender.com');
});

it('envia SMS para /messages/send com o header X-API-Key e os campos to/text', function () {
    Http::fake(['*/messages/send' => Http::response([
        'success' => true,
        'message_id' => 'SM123',
        'status' => 'queued',
    ], 201)]);

    $ok = $this->provider->send('+244912345678', 'Olá do KeruGás');

    expect($ok)->toBeTrue();
    Http::assertSent(function ($request) {
        return $request->hasHeader('X-API-Key', 'fake-api-key')
            && $request->url() === 'https://nexasms-api.onrender.com/messages/send'
            && $request['to'] === '+244912345678'
            && $request['text'] === 'Olá do KeruGás'
            && !array_key_exists('sender_id', $request->data());
    });
});

it('devolve false quando a Kamba recusa o envio (ex: 402 saldo insuficiente)', function () {
    Http::fake(['*/messages/send' => Http::response(['error' => 'Saldo insuficiente.'], 402)]);

    $ok = $this->provider->send('+244912345678', 'Olá');

    expect($ok)->toBeFalse();
});

it('verifyWebhook() rejeita sempre — a API da Kamba não tem endpoint de callback', function () {
    Http::fake();

    $ok = $this->provider->verifyWebhook([], json_encode(['id' => 'msg-1', 'status' => 'delivered']));

    expect($ok)->toBeFalse();
    Http::assertNothingSent();
});

it('consulta o saldo de créditos em /credits/balance', function () {
    Http::fake(['*/credits/balance' => Http::response(['balance' => 47], 200)]);

    expect($this->provider->creditsBalance())->toBe(47);
});

it('devolve null para o saldo quando a API não responde (falha fechada)', function () {
    Http::fake(['*/credits/balance' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

    expect($this->provider->creditsBalance())->toBeNull();
});
