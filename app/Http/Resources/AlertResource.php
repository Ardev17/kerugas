<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'station' => $this->whenLoaded('station', fn () => $this->station?->name),
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality?->name),
            'max_distance_km' => $this->max_distance_km,
            'channels' => $this->channels,
            'active' => $this->active,
            'last_triggered_at' => $this->last_triggered_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
