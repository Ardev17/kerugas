<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Implementação de desenvolvimento/demo. Não envia SMS reais —
 * apenas regista no log. Usada quando SMS_PROVIDER=mock.
 */
class MockSmsProvider implements SmsProviderInterface
{
    public function send(string $to, string $message): bool
    {
        Log::channel('single')->info('[MockSmsProvider] SMS simulado', [
            'to' => $to,
            'message' => $message,
        ]);

        return true;
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        // AUDITORIA DE SEGURANÇA (Setembro 2026): esta implementação
        // aceitava QUALQUER webhook sem assinatura nenhuma — seguro só
        // enquanto `SMS_PROVIDER=production` estiver corretamente
        // definido no ambiente real. Se essa variável fosse esquecida
        // num deploy (ou nunca definida — o default em config/kerugas.php
        // é "mock"), o webhook de SMS ficaria completamente aberto a
        // forjar atualizações de estado de postos, sem assinatura
        // nenhuma. Este guard fecha essa lacuna na origem: nunca aceita
        // um webhook não assinado quando o `APP_ENV` é `production`,
        // seja qual for o valor de `SMS_PROVIDER` nesse momento.
        if (app()->environment('production')) {
            Log::channel('single')->critical(
                '[MockSmsProvider] Webhook de SMS recebido em produção com o provedor mock ativo — REJEITADO. '
                . 'Verifique a variável SMS_PROVIDER no .env do servidor.'
            );

            return false;
        }

        // Em desenvolvimento/testes, aceitar tudo — nunca usar isto em produção.
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
