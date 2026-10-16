<?php

namespace App\Events;

use App\Models\RoomMessage;
use App\Services\RoomChat\RoomAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Conversas por sala (Outubro 2026): avisa quem está na sala de que há
 * mensagens novas. Condutores: o mesmo canal e nome de sempre
 * (`municipality.{id}.chat` / `chat.message_sent`), para as versões antigas
 * da app continuarem a atualizar. Central: `central.{utilizador}`.
 * Só leva o id — o conteúdo vem sempre da API.
 */
class RoomMessagePosted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $messageId, public readonly string $roomType, public readonly int $roomId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(RoomAccess::channel($this->roomType, $this->roomId))];
    }

    public function broadcastAs(): string
    {
        return match ($this->roomType) {
            RoomMessage::DRIVERS => 'chat.message_sent',
            RoomMessage::INVESTOR => 'investor.message_posted',
            default => 'central.message_posted',
        };
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId, 'room_type' => $this->roomType, 'room_id' => $this->roomId];
    }

    public static function dispatchSafely(RoomMessage $message): void
    {
        try {
            static::dispatch((int) $message->id, $message->room_type, (int) $message->room_id);
        } catch (\Throwable) {
            // tempo real é um extra: o polling do ecrã cobre a falha
        }
    }
}
