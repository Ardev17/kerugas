<?php

namespace App\Listeners;

use App\Events\GasOrderCreated;
use App\Models\AppNotification;
use App\Services\OrderAudience;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifica ADMIN/SUPER_ADMIN sobre novos pedidos de gás (secção 192).
 * Na Fase 1, o canal implementado é in-app; os restantes (email, SMS,
 * push) ficam preparados pela abstração de NotificationChannel mas
 * não estão ligados ainda — evitar enviar SMS/email não configurados.
 *
 * BUG CORRIGIDO: implementava `ShouldQueue`, mas sem nenhum
 * `php artisan queue:work` a correr (setup local típico deste
 * projeto, QUEUE_CONNECTION=sync), isso não traz benefício nenhum —
 * continua a correr de forma síncrona — e teve um efeito colateral
 * real: uma exceção aqui dentro nem sempre era apanhada da mesma
 * forma pelo `try/catch` à volta do `event()` em GasOrderService,
 * porque o despacho para um listener em fila passa por um caminho de
 * tratamento de falhas diferente do de um listener normal. Resultado:
 * o pedido ficava guardado com sucesso (a transação principal já
 * tinha terminado), mas a resposta HTTP vinha com 500 mesmo assim.
 * Removido `ShouldQueue` e blindado o corpo com o seu próprio
 * try/catch — notificar admins é sempre "melhor esforço", nunca deve
 * poder rebentar a criação do pedido em si.
 */
class NotifyAdminsOfNewOrderListener
{
    public function handle(GasOrderCreated $event): void
    {
        try {
            $order = $event->gasOrder;

            // Outubro 2026: um Admin só é avisado dos pedidos da SUA província
            // (antes todos os Admins recebiam todos os pedidos do país).
            $admins = app(OrderAudience::class)->admins($order);

            foreach ($admins as $admin) {
                AppNotification::create([
                    'user_id' => $admin->id,
                    'category' => 'order',
                    'title' => 'Novo pedido de gás',
                    'body' => "Pedido {$order->order_reference} de {$order->customer_name} aguarda contacto.",
                    'data' => ['gas_order_id' => $order->id, 'reference' => $order->order_reference],
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('single')->warning('[NotifyAdminsOfNewOrderListener] Falha ao notificar admins (não crítico)', [
                'gas_order_id' => $event->gasOrder->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
