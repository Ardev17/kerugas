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

    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->station->id,
            'gas_available' => $this->statusUpdate->gas_available,
            'queue_level' => $this->statusUpdate->queue_level->value,
        ];
    }
}
