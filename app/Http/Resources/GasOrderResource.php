<?php

namespace App\Http\Resources;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
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
            // IDs (Setembro 2026): o "repetir último pedido" das
            // notificações preenche o formulário com a mesma zona.
            'province_id' => $this->province_id,
            'municipality_id' => $this->municipality_id,
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
            'assigned_to' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser?->name),
            'assigned_to_avatar_url' => $this->whenLoaded('assignedUser', fn () => \App\Services\Media\AvatarService::url($this->assignedUser?->avatar_path)),
            'assigned_to_role' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser?->role?->value),
            // Avaliação do condutor (Outubro 2026): só calculado quando as
            // relações `rating` e `assignedUser` vêm carregadas (evita N+1
            // nas listas). `can_rate` espelha as regras do DriverRatingController.
            'driver_rating' => $this->whenLoaded('rating', fn () => $this->rating?->rating),
            'can_rate' => $this->when(
                $this->relationLoaded('rating') && $this->relationLoaded('assignedUser'),
                fn () => $this->status === GasOrderStatus::DELIVERED
                    && $this->assignedUser?->role === UserRole::DRIVER
                    && $this->rating === null,
            ),
            'created_at' => $this->created_at->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'contacted_at' => $this->contacted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Faturação por entrega (Setembro 2026): só existe a partir
            // do momento em que o pedido é confirmado — antes disso o
            // preço ainda está a ser negociado por telefone.
            'delivery_price' => $this->delivery_price !== null ? (float) $this->delivery_price : null,
            // Divisão posto/comissão (Outubro 2026): SÓ para Admin/Super Admin.
            // O cliente, o condutor e o acompanhamento público veem apenas o
            // total (`delivery_price`) — nunca o valor das garrafas nem a comissão.
            ...$this->billingFields($request),
            ...$this->internalFields($request),
        ];
    }

    /** @return array<string, mixed> */
    /**
     * Dados mínimos (Outubro 2026): o histórico interno da equipa (notas de
     * cada passo e nomes de quem as escreveu), a prioridade e quem definiu
     * ou corrigiu o preço só servem a quem GERE os pedidos. O cliente e o
     * condutor recebem o pedido sem estes campos — nenhum ecrã deles os
     * usa, e as notas internas nunca devem chegar ao telemóvel do cliente.
     */
    private function internalFields(Request $request): array
    {
        $role = $request->user()?->role;
        if ($role === null || in_array($role, [UserRole::USER, UserRole::DRIVER], true)) {
            return [];
        }

        return [
            'priority' => $this->priority?->value,
            'assigned_to_id' => $this->assigned_to,
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

    private function billingFields(Request $request): array
    {
        $role = $request->user()?->role;
        if (!in_array($role, UserRole::managementRoles(), true)) {
            return [];
        }

        return [
            'bottle_price' => $this->bottle_price !== null ? (float) $this->bottle_price : null,
            'commission' => $this->commission !== null ? (float) $this->commission : null,
            'billing_complete' => $this->hasBillingSplit(),
        ];
    }
}
