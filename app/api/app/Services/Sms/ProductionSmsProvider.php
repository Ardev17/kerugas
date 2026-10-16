<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Implementação de referência para um provedor real de SMS em produção.
 *
 * DECISÃO TÉCNICA: como o fornecedor definitivo (operadora angolana,
 * Africa's Talking, Twilio, etc.) ainda não foi confirmado, esta classe
 * implementa um contrato HTTP genérico configurável via .env, para não
 * bloquear o desenvolvimento. Ao integrar o fornecedor real, substitua
 * apenas o corpo dos métodos — a interface e o resto do sistema não mudam.
 */
class ProductionSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $webhookSecret,
    ) {
    }

    public function send(string $to, string $message): bool
    {
        $response = Http::withToken($this->apiKey)
            ->post(config('services.sms.send_url', ''), [
                'to' => $to,
                'message' => $message,
            ]);

        return $response->successful();
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $signature = $headers['x-kerugas-signature'][0] ?? '';
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        return hash_equals($expected, $signature) && Str::length($signature) > 0;
    }

    public function normalizeIncomingMessage(array $payload): array
    {
        return [
            'provider_message_id' => $payload['id'] ?? null,
            'sender' => $payload['sender'] ?? '',
            'recipient' => $payload['recipient'] ?? null,
            'body' => $payload['message'] ?? '',
            'received_at' => isset($payload['timestamp'])
                ? \Illuminate\Support\Carbon::parse($payload['timestamp'])
                : now(),
        ];
    }
}
