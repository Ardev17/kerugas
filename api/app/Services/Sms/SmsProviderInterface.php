<?php

namespace App\Services\Sms;

/**
 * Abstração de provedor de SMS. O sistema nunca deve acoplar-se
 * diretamente a um fornecedor específico (Twilio, Africa's Talking,
 * gateway local, etc.). Toda a lógica de negócio conversa apenas
 * com esta interface.
 */
interface SmsProviderInterface
{
    /**
     * Envia um SMS para um número normalizado.
     */
    public function send(string $to, string $message): bool;

    /**
     * Valida a assinatura/autenticidade de um webhook recebido do provedor.
     * Deve proteger contra replay attacks e requisições forjadas.
     */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    /**
     * Normaliza o payload bruto do provedor para um formato interno comum.
     *
     * @return array{provider_message_id: ?string, sender: string, recipient: ?string, body: string, received_at: \DateTimeInterface}
     */
    public function normalizeIncomingMessage(array $payload): array;
}
