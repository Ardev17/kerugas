<?php

namespace App\Events;

use App\Models\Station;
use App\Models\StationStatusUpdate;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StationStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Station $station,
        public readonly StationStatusUpdate $statusUpdate,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('stations')];
    }

    public function broadcastAs(): string
    {
        return 'station.updated';
    }

    /**
     * Só um toque, sem conteúdo (auditoria de segurança, Novembro 2026).
     *
     * O canal 'stations' é público: qualquer pessoa com a chave do Reverb —
     * que vai dentro da aplicação,o pode ouvir sem conta. Enquanto levava o
     * gás e a fila de cada posto, era a forma mais simples de ter o país
     * inteiro em tempo real sem pagar a consulta, e de ver postos que deixaram
     * de pagar a presença. A aplicação nunca usou esses campos: só recarrega o
     * mapa, e o mapa já aplica as regras a quem pergunta.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
