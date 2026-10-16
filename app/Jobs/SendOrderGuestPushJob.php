<?php

namespace App\Jobs;

use App\Models\GasOrder;
use App\Models\OrderPushSubscription;
use App\Services\Push\PushMessage;
use App\Services\Push\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aviso push de um pedido para os navegadores de um cliente SEM conta
 * (Outubro 2026). Fica de fora da fila de notificações do app porque não
 * há utilizador a quem ligar uma `AppNotification`.
 *
 * `$final` = estado final (entregue/cancelado): depois deste aviso as
 * subscrições do pedido apagam-se — não há mais nada a comunicar e não
 * guardamos dados de navegadores sem necessidade.
 */
class SendOrderGuestPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $orderId,
        public readonly string $title,
        public readonly string $body,
        public readonly bool $final = false,
    ) {
        // Sem afterCommit: GasOrderService só chama isto DEPOIS de a transação
        // da mudança de estado ter terminado, por isso o estado já está gravado.
    }

    public function handle(?WebPushSender $webPush = null): void
    {
        $webPush ??= app(WebPushSender::class);

        $order = GasOrder::find($this->orderId);
        if (!$order) {
            return;
        }

        if ($webPush->isEnabled()) {
            $message = new PushMessage(
                title: $this->title,
                body: $this->body,
                path: '/pedido/' . rawurlencode($order->order_reference),
                channel: PushMessage::CHANNEL_ORDERS,
            );

            OrderPushSubscription::where('gas_order_id', $order->id)->each(function (OrderPushSubscription $subscription) use ($webPush, $message) {
                try {
                    // O sender espera um objeto com os campos da subscrição.
                    if (!$webPush->send($subscription, $message)) {
                        $subscription->delete();
                    }
                } catch (Throwable $e) {
                    Log::channel('single')->warning('[SendOrderGuestPushJob] Falha num navegador (os restantes continuam)', [
                        'order_push_subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        if ($this->final) {
            OrderPushSubscription::where('gas_order_id', $order->id)->delete();
        }
    }
}
