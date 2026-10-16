<?php

namespace Tests\Support;

use App\Services\Sms\SmsProviderInterface;

/**
 * Fake configurável do gateway de SMS para testes (secção 69 do master
 * prompt). Nunca faz chamadas reais — apenas regista o que lhe é
 * pedido, para os testes poderem afirmar sobre o comportamento do
 * KeruGás sem depender de um provider externo.
 */
class FakeSmsProvider implements SmsProviderInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function __construct(private bool $shouldSucceed = true)
    {
    }

    public function send(string $to, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return $this->shouldSucceed;
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function normalizeIncomingMessage(array $payload): array
    {
        return [
            'provider_message_id' => $payload['message_id'] ?? null,
            'sender' => $payload['from'] ?? '',
            'recipient' => $payload['to'] ?? null,
            'body' => $payload['text'] ?? '',
            'received_at' => now(),
        ];
    }
}
