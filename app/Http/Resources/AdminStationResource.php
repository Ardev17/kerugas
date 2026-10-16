<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versão administrativa do posto — expõe sms_phone e outros campos
 * operacionais que o StationResource público nunca deve mostrar.
 */
class AdminStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'slug' => $this->slug,
            'province_id' => $this->province_id,
            'municipality_id' => $this->municipality_id,
            'province' => $this->whenLoaded('province', fn () => $this->province->name),
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality->name),
            'address' => $this->address,
            'reference_point' => $this->reference_point,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'phone' => $this->phone,
            'sms_phone' => $this->sms_phone,
            // Vários operadores por posto (Outubro 2026). `operator_id`/`operator_name`
            // ficam para compatibilidade (primeiro operador / nomes juntos).
            'operators' => $this->whenLoaded('operators', fn () => $this->operators->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone])->values()),
            'operator_ids' => $this->whenLoaded('operators', fn () => $this->operators->pluck('id')->values()),
            'operator_id' => $this->whenLoaded('operators', fn () => $this->operators->first()?->id),
            'operator_name' => $this->whenLoaded('operators', fn () => $this->operators->isEmpty() ? null : $this->operators->pluck('name')->join(', ')),
            'analytics_enabled' => (bool) $this->analytics_enabled,
            'opening_hours' => $this->opening_hours,
            'status' => $this->status->value,
            'gas_available' => (bool) $this->current_gas_available,
            'queue_level' => $this->current_queue_level?->value,
            'cylinder_types' => $this->current_cylinder_types,
            'confidence_score' => $this->current_confidence_score,
            'status_updated_at' => $this->status_updated_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
