<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Integração com a KambaSMS (fornecedor angolano, confirmado com o
 * suporte deles em Setembro 2026 — cobertura Unitel/Movicel/Africell,
 * faturação em Kwanza, Sender ID personalizado).
 *
 * Endpoints reais confirmados via `https://www.kambasms.ao/dashboard/docs`
 * (a documentação Postman inicialmente partilhada só cobria autenticação
 * genérica — os endpoints e nomes de campos abaixo vêm da doc completa):
 *   - Base URL: https://nexasms-api.onrender.com
 *   - Autenticação: header `X-API-Key` em todos os pedidos (exceto
 *     `/otp/verify`, que é público).
 *   - `POST /messages/send` — { to, text } — envia um SMS. O Sender ID
 *     é definido automaticamente a partir da API key (não se envia no
 *     corpo do pedido — ver "Sender ID" no formulário de criação da
 *     chave no dashboard deles).
 *   - `GET /messages` — histórico das últimas 100 mensagens enviadas.
 *   - `GET /credits/balance` — saldo de créditos disponível.
 *   - Limites: 50 SMS/hora por conta, 100 pedidos/15min por IP.
 *
 * DECISÃO TÉCNICA — `verifyWebhook()` rejeita sempre: a documentação
 * completa da Kamba (a mesma referida acima) NÃO tem nenhum endpoint de
 * callback/webhook — é só uma API que NÓS consultamos (enviar, listar
 * histórico, ver saldo). O que o suporte comercial descreveu como "webhook
 * que mostra o histórico" acabou por ser, na prática, o painel/endpoint de
 * histórico (`GET /messages`), nunca um push deles para o nosso servidor.
 * Como o KeruGás já tinha decidido só precisar de um gateway de SMS
 * OUTBOUND (nunca processamos SMS recebidos via este fornecedor — isso
 * ficou só para a Central de Operações, que é manual, por telefone físico),
 * não há nenhum cenário legítimo em que a Kamba bata à rota
 * `/webhooks/sms`. Rejeitar sempre aqui é mais correto do que fingir uma
 * verificação de assinatura que a API nem oferece.
 */
class KambaSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {
    }

    public function send(string $to, string $message): bool
    {
        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->post("{$this->baseUrl}/messages/send", [
                'to' => $to,
                'text' => $message,
            ]);

        if (!$response->successful()) {
            // 402 = saldo insuficiente (ver "Créditos" na doc) — vale a
            // pena distinguir no log de um 400/401 comuns, porque este
            // exige ação humana (recarregar), não é um bug.
            Log::channel('single')->warning('[KambaSmsProvider] Falha ao enviar SMS', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        Log::channel('single')->warning(
            '[KambaSmsProvider] Callback recebido em /webhooks/sms, mas a API da Kamba não tem webhooks — rejeitado sempre. '
            . 'Verifique a origem deste pedido (a Kamba nunca deveria chamar este endpoint).'
        );

        return false;
    }

    public function normalizeIncomingMessage(array $payload): array
    {
        // Nunca chamado na prática — verifyWebhook() acima rejeita
        // sempre antes de qualquer payload da Kamba chegar aqui.
        // Mantido só para cumprir SmsProviderInterface.
        return [
            'provider_message_id' => null,
            'sender' => '',
            'recipient' => null,
            'body' => '',
            'received_at' => now(),
        ];
    }

    /**
     * Consulta o saldo de créditos disponível (`GET /credits/balance`).
     * Não faz parte de `SmsProviderInterface` — utilitário específico da
     * Kamba, útil para alertar antes de ficar sem créditos (evita o 402
     * "Payment Required" a meio de um envio real a um cliente).
     */
    public function creditsBalance(): ?int
    {
        try {
            $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
                ->timeout(5)
                ->get("{$this->baseUrl}/credits/balance");

            return $response->successful() ? (int) $response->json('balance') : null;
        } catch (Throwable $e) {
            Log::channel('single')->error('[KambaSmsProvider] Erro ao consultar saldo de créditos', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
