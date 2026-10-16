<?php

namespace App\Events;

use App\Models\CommunityMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Vizinhos KeruGás (Outubro 2026): avisa quem está na sala de que há
 * mensagens novas. Só leva o id — o conteúdo (já filtrado pelo que cada
 * pessoa pode ver) vem sempre da API, nunca pelo canal.
 */
class CommunityMessagePosted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $municipalityId, public readonly int $messageId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("community.municipality.{$this->municipalityId}")];
    }

    public function broadcastAs(): string
    {
        return 'community.message_posted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId, 'municipality_id' => $this->municipalityId];
    }

    public static function dispatchSafely(CommunityMessage $message): void
    {
        try {
            static::dispatch((int) $message->municipality_id, (int) $message->id);
        } catch (\Throwable) {
            // tempo real é um extra: o polling do ecrã cobre a falha
        }
    }
}
