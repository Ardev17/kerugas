<?php

namespace App\Events;

use App\Models\StaffChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chat da Equipa (Outubro 2026): avisa os admins de que há mensagens novas.
 * Só leva o id — o conteúdo vem sempre da API.
 */
class StaffChatMessagePosted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $messageId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff.chat')];
    }

    public function broadcastAs(): string
    {
        return 'staff_chat.message_posted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId];
    }

    public static function dispatchSafely(StaffChatMessage $message): void
    {
        try {
            static::dispatch((int) $message->id);
        } catch (\Throwable) {
            // tempo real é um extra: o polling do ecrã cobre a falha
        }
    }
}
