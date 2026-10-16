<?php

namespace App\Http\Resources;

use App\Services\FreshnessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nunca retorna o Model Eloquent diretamente — apenas os campos
 * necessários ao consumo pelo frontend (secção 50).
 */
class StationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $freshness = app(FreshnessService::class)->level($this->status_updated_at);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'slug' => $this->slug,
            'province' => $this->whenLoaded('province', fn () => $this->province->name),
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality->name),
            'address' => $this->address,
            'reference_point' => $this->reference_point,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'phone' => $this->phone,
            'opening_hours' => $this->opening_hours,
            'photo_url' => $this->photo_path ? asset('storage/' . $this->photo_path) : null,
            'status' => $this->status->value,
            'gas_available' => (bool) $this->current_gas_available,
            'queue_level' => $this->current_queue_level?->value,
            'cylinder_types' => $this->current_cylinder_types,
            'confidence_score' => $this->current_confidence_score,
            'freshness' => $freshness->value,
            'freshness_label' => $freshness->label(),
            'status_updated_at' => $this->status_updated_at?->toIso8601String(),
            'distance_km' => $this->when(isset($this->distance_km), fn () => round((float) $this->distance_km, 2)),
        ];
    }
}
