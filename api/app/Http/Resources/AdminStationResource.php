<?php

namespace App\Http\Resources;

use App\Enums\StationListingOverride;
use App\Enums\StationOrdersOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versão administrativa do posto,expõe sms_phone e outros campos
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
            // Interruptor dos pedidos (Novembro 2026).
            'orders_override' => ($this->orders_override ?? StationOrdersOverride::INHERIT)->value,
            'orders_override_reason' => $this->orders_override_reason,
            'orders_override_at' => $this->orders_override_at?->toIso8601String(),
            // Presença paga na aplicação (Novembro 2026, a dormir).
            'listing_override' => ($this->listing_override ?? StationListingOverride::INHERIT)->value,
            'listing_override_reason' => $this->listing_override_reason,
            'platform_owned' => (bool) $this->platform_owned,
            // Global, repetido por linha de propósito: poupa ao painel uma
            // chamada só para saber se o mecanismo da presença já está ligado.
            'listing_enforced' => app(\App\Services\Subscriptions\SubscriptionSettings::class)->listingEnforced(),
            // Dia em que sai do mapa, se estiver a viver da tolerância.
            'listing_grace_until' => app(\App\Services\Stations\StationListingService::class)
                ->toleranciaAcaba($this->resource)?->toIso8601String(),
            // Visível hoje? O analytics do operador vem agarrado a isto
            // desde Novembro de 2026.
            'is_listed' => app(\App\Services\Stations\StationListingService::class)->isListed($this->resource),
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
