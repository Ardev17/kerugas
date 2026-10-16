<?php

namespace App\Services\Sms;

use App\Enums\SmsDispatchStatus;
use App\Exceptions\SmsDailyLimitExceededException;
use App\Models\Operation;
use App\Models\SmsDispatch;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envia SMS através do provedor configurado (SmsProviderInterface) e
 * regista o histórico em `sms_dispatches`. Centraliza validação,
 * idempotência e tratamento de erros (secções 26, 29, 42, 77).
 */
class SmsDispatchService
{
    public function __construct(
        private readonly SmsProviderInterface $provider,
    ) {
    }

    public function dispatch(
        User $admin,
        string $recipient,
        string $message,
        ?SmsTemplate $template = null,
        ?Operation $operation = null,
    ): SmsDispatch {
        // Idempotência: se já existe um envio bem-sucedido/pendente para
        // esta mesma operação, devolve-o em vez de enviar duplicado
        // (protege contra duplo clique ou retry de rede — secção 42).
        if ($operation) {
            $existing = SmsDispatch::where('operation_id', $operation->id)
                ->whereIn('status', [SmsDispatchStatus::PENDING, SmsDispatchStatus::QUEUED, SmsDispatchStatus::SENT, SmsDispatchStatus::DELIVERED])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Limite diário por Admin (Setembro 2026, pedido explícito do
        // cliente — controlo de custo/auditoria). `sms_daily_limit` a
        // `null` significa "sem limite" (comportamento anterior,
        // preservado para quem o Super Admin não configurou). Verificado
        // aqui — no serviço central, não no controller — para cobrir
        // qualquer caminho futuro que venha a chamar dispatch(), não só
        // a Central de Operações.
        if ($admin->sms_daily_limit !== null) {
            $sentToday = $this->sentTodayCountFor($admin);

            if ($sentToday >= $admin->sms_daily_limit) {
                throw new SmsDailyLimitExceededException($admin->sms_daily_limit, $sentToday);
            }
        }

        $dispatch = SmsDispatch::create([
            'provider' => config('kerugas.sms.provider', 'mock'),
            'recipient' => $recipient,
            'message' => $message,
            'sms_template_id' => $template?->id,
            'operation_id' => $operation?->id,
            'status' => SmsDispatchStatus::PENDING,
            'created_by' => $admin->id,
        ]);

        try {
            $accepted = $this->provider->send($recipient, $message);

            $dispatch->update($accepted
                ? ['status' => SmsDispatchStatus::SENT, 'sent_at' => now()]
                : [
                    'status' => SmsDispatchStatus::FAILED,
                    'failed_at' => now(),
                    'error_message' => 'O provedor de SMS recusou o envio.',
                ]);
        } catch (Throwable $e) {
            Log::channel('single')->error('[SmsDispatchService] falha ao enviar SMS', [
                'dispatch_id' => $dispatch->id,
                'error' => $e->getMessage(),
            ]);

            $dispatch->update([
                'status' => SmsDispatchStatus::FAILED,
                'failed_at' => now(),
                'error_code' => class_basename($e),
                'error_message' => 'Não foi possível enviar o SMS. Tente novamente dentro de instantes.',
            ]);
        }

        return $dispatch->fresh();
    }

    /**
     * Quantos SMS este Admin já enviou hoje, para efeitos de limite
     * diário. Conta tudo exceto FAILED — um envio que o provedor
     * recusou/falhou não é "culpa" nem custo do Admin, não deve gastar
     * a sua quota (ver também usageByAdmin() em SmsDispatchController,
     * que usa a mesma regra de contagem para a vista do Super Admin).
     */
    public function sentTodayCountFor(User $admin): int
    {
        return SmsDispatch::where('created_by', $admin->id)
            ->whereDate('created_at', now()->toDateString())
            ->where('status', '!=', SmsDispatchStatus::FAILED)
            ->count();
    }

    /**
     * Atualiza o estado a partir de um callback/webhook de entrega real
     * do provedor (nunca inventado — só chamado quando o gateway
     * configurado efetivamente suportar delivery tracking).
     */
    public function markDelivered(SmsDispatch $dispatch, ?string $providerMessageId = null): SmsDispatch
    {
        $dispatch->update([
            'status' => SmsDispatchStatus::DELIVERED,
            'delivered_at' => now(),
            'provider_message_id' => $providerMessageId ?? $dispatch->provider_message_id,
        ]);

        return $dispatch->fresh();
    }

    public function markFailedByCallback(SmsDispatch $dispatch, ?string $errorCode, ?string $errorMessage): SmsDispatch
    {
        $dispatch->update([
            'status' => SmsDispatchStatus::FAILED,
            'failed_at' => now(),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        return $dispatch->fresh();
    }
}
