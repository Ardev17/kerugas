<?php

namespace App\Events;

use App\Models\GasOrder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Tempo real do pedido criado (canal privado admin.orders) — só transmissão. */
class GasOrderCreatedBroadcast implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly GasOrder $gasOrder)
    {
    }

    /**
     * Canal PRIVADO, de propósito: um pedido de gás contém nome e
     * telefone do cliente. Nunca deve ir para um canal público, que
     * qualquer pessoa ligada ao WebSocket poderia escutar. A autorização
     * (só ADMIN/SUPER_ADMIN podem subscrever) está em routes/channels.php.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.orders')];
    }

    public function broadcastAs(): string
    {
        return 'gas_order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->gasOrder->id,
            'reference' => $this->gasOrder->order_reference,
            'municipality_id' => $this->gasOrder->municipality_id,
            'priority' => $this->gasOrder->priority?->value,
        ];
    }
}
