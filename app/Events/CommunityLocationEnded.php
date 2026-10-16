<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Vizinhos (Outubro 2026): uma partilha de localização acabou (expirou ou a
 * pessoa parou). Os ecrãs abertos tiram o mapa dessa mensagem na hora. Só leva
 * o id — nunca coordenadas.
 */
class CommunityLocationEnded implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public readonly int $municipalityId, public readonly int $messageId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("community.municipality.{$this->municipalityId}")];
    }

    public function broadcastAs(): string
    {
        return 'community.location_ended';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId];
    }

    public static function dispatchSafely(int $municipalityId, int $messageId): void
    {
        try {
            static::dispatch($municipalityId, $messageId);
        } catch (\Throwable) {
            // tempo real é um extra: o ecrã também verifica sozinho
        }
    }
}
