<?php

namespace App\Observers;

use App\Jobs\SendPushForNotificationJob;
use App\Models\AppNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cada notificação criada no app passa também a push (Setembro 2026).
 *
 * O envio vai para a fila, e SÓ depois de a transação da base de dados
 * confirmar (`afterCommit` no job): os alertas de disponibilidade são
 * criados dentro de uma transação (DispatchStationAlertsListener), e se
 * ela fosse revertida não pode ter saído um push para algo que não
 * existe. Nunca bloqueia nem rebenta quem criou a notificação.
 */
class AppNotificationObserver
{
    public function created(AppNotification $notification): void
    {
        try {
            SendPushForNotificationJob::dispatch($notification->id);
        } catch (Throwable $e) {
            Log::channel('single')->warning('[AppNotificationObserver] Não foi possível enfileirar o push (não crítico)', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
