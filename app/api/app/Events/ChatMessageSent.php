<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tempo real via Reverb (pedido explícito do cliente, Setembro 2026 —
 * "consistência" com `GasOrderCreated`/`admin.orders`). Canal PRIVADO
 * por município (`municipality.{id}.chat`) — nunca público, porque
 * mesmo uma pergunta de chat pode conter dados operacionais (nome de
 * rua, referência de entrega). A autorização de quem pode subscrever
 * cada canal (condutor do próprio município; admin da respetiva
 * província; super admin sempre) vive em routes/channels.php.
 */
class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ChatMessage $message)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("municipality.{$this->message->municipality_id}.chat")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message_sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'body' => $this->message->body,
            'user' => $this->message->user?->name,
            'user_id' => $this->message->user_id,
            'role' => $this->message->user?->role?->value,
            'province_id' => $this->message->province_id,
            'municipality_id' => $this->message->municipality_id,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
