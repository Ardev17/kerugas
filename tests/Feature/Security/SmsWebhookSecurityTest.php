<?php

use App\Models\Station;
use App\Services\Sms\MockSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Auditoria de segurança (Setembro 2026): duas correções ao webhook de
 * SMS — (1) rate limit, antes inexistente; (2) o provedor mock nunca
 * mais aceita webhooks não assinados quando `APP_ENV=production`,
 * mesmo que `SMS_PROVIDER` tenha sido deixado em "mock" por engano.
 */
beforeEach(function () {
    // O rate limiter guarda os contadores na cache — limpar entre
    // testes evita que um teste "empreste" tentativas já gastas por
    // outro dentro do mesmo ficheiro.
    Cache::flush();
});
it('MockSmsProvider rejeita webhooks quando o ambiente é produção, mesmo com SMS_PROVIDER=mock', function () {
    app()['env'] = 'production';

    $provider = new MockSmsProvider();

    expect($provider->verifyWebhook([], '{}'))->toBeFalse();
});

it('MockSmsProvider continua a aceitar tudo fora de produção (dev/testes)', function () {
    $provider = new MockSmsProvider();

    expect($provider->verifyWebhook([], '{}'))->toBeTrue();
});

it('o webhook de SMS devolve 401 em produção com o provedor mock, em vez de processar o pedido', function () {
    app()['env'] = 'production';

    $response = $this->postJson('/api/v1/webhooks/sms', [
        'message_id' => 'abc123',
        'from' => '+244912345678',
        'to' => '+244923456789',
        'text' => '10',
    ]);

    $response->assertStatus(401);
});

it('o webhook de SMS aplica rate limit (30/min por IP) — antes não tinha nenhum', function () {
    Station::factory()->create(['sms_phone' => '+244900000001']);

    $payload = [
        'message_id' => 'msg-',
        'from' => '+244900000001',
        'to' => '+244900000001',
        'text' => '10',
    ];

    for ($i = 1; $i <= 30; $i++) {
        $response = $this->postJson('/api/v1/webhooks/sms', array_merge($payload, ['message_id' => "msg-{$i}"]));
        $response->assertStatus(200);
    }

    $response = $this->postJson('/api/v1/webhooks/sms', array_merge($payload, ['message_id' => 'msg-31']));
    $response->assertStatus(429);
});
