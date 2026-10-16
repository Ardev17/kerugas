<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GasOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->order_reference,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'province' => $this->whenLoaded('province', fn () => $this->province->name),
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality->name),
            'neighborhood' => $this->neighborhood,
            'address' => $this->address,
            'reference_point' => $this->reference_point,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
            // REGRESSÃO CORRIGIDA (de novo): estes 3 campos são enums
            // castados no Model e podem vir `null` se alguma vez um
            // registo for criado sem passar pelo StoreGasOrderRequest
            // (ex: seeder, import, chamada direta ao Service). Acesso
            // sem `?->` aqui causa "Attempt to read property on null"
            // e um 500 na resposta — já aconteceu antes.
            'location_source' => $this->location_source?->value,
            'quantity' => $this->quantity,
            'cylinder_type' => $this->cylinder_type,
            // Confirmação de garrafas (Setembro 2026) — distinto de
            // `cylinder_type` (estimativa inicial do cliente); só
            // existe a partir da confirmação do pedido pelo Admin.
            'cylinder_types_confirmed' => $this->cylinder_types_confirmed,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'priority' => $this->priority?->value,
            'assigned_to' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser?->name),
            'assigned_to_id' => $this->assigned_to,
            'assigned_to_role' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser?->role?->value),
            'created_at' => $this->created_at->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'contacted_at' => $this->contacted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Faturação por entrega (Setembro 2026): só existe a partir
            // do momento em que o pedido é confirmado — antes disso o
            // preço ainda está a ser negociado por telefone.
            'delivery_price' => $this->delivery_price !== null ? (float) $this->delivery_price : null,
            'price_set_by' => $this->whenLoaded('priceSetBy', fn () => $this->priceSetBy?->name),
            'price_set_at' => $this->price_set_at?->toIso8601String(),
            'price_corrected_by' => $this->whenLoaded('priceCorrectedBy', fn () => $this->priceCorrectedBy?->name),
            'price_corrected_at' => $this->price_corrected_at?->toIso8601String(),
            'events' => $this->whenLoaded('events', fn () => $this->events
                ->sortBy('created_at')
                ->values()
                ->map(fn ($e) => [
                    'action' => $e->action,
                    'from_status' => $e->from_status,
                    'to_status' => $e->to_status,
                    'notes' => $e->notes,
                    'user' => $e->user?->name,
                    'created_at' => $e->created_at->toIso8601String(),
                ])),
        ];
    }
}
