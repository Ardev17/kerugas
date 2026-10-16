<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status,
            'province' => $this->whenLoaded('province', fn () => $this->province?->name),
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality?->name),
            'province_id' => $this->province_id,
            'municipality_id' => $this->municipality_id,
            // Vizinhos KeruGás (Outubro 2026): o bairro vai no perfil e no login.
            'neighborhood_id' => $this->neighborhood_id,
            'neighborhood' => $this->whenLoaded('neighborhood', fn () => $this->neighborhood?->name),
            'community_accepted' => $this->community_accepted_at !== null,
            // Localização exata opcional (nunca pública — este Resource só é
            // usado em rotas autenticadas: /users/me e /admin/users).
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            // Condutor (Setembro 2026) — só tem significado prático
            // quando role === DRIVER; incluído sempre por simplicidade
            // (é só um boolean, sem custo/risco de expor).
            'driver_available' => (bool) $this->driver_available,
            // Limite diário de SMS (Setembro 2026) — só tem significado
            // prático quando role === ADMIN; `null` = sem limite.
            'sms_daily_limit' => $this->sms_daily_limit,
            // Permissão do Admin para promover/alterar papéis (Outubro 2026).
            'can_promote_users' => (bool) $this->can_promote_users,
            // Meu Perfil (Outubro 2026).
            'created_at' => $this->created_at?->toIso8601String(),
            // Foto de perfil (Outubro 2026) — URL público, ou null.
            'avatar_url' => \App\Services\Media\AvatarService::url($this->avatar_path),
            // Eliminação pedida (Outubro 2026): data em que a conta será eliminada.
            'deletion_scheduled_for' => $this->deletion_scheduled_for?->toIso8601String(),
        ];
    }
}
