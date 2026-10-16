<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'status' => $this->status->value,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name ?? 'Conta eliminada'),
            'user_avatar_url' => $this->whenLoaded('user', fn () => \App\Services\Media\AvatarService::url($this->user?->avatar_path)),
            // Só nas listas de moderação (com o posto carregado) — para a
            // remoção de fotos impróprias; nunca nas listas públicas.
            'user_id' => $this->when($this->relationLoaded('station'), fn () => $this->user_id),
            // Posto comentado (Outubro 2026) — só nas listas de moderação,
            // onde a relação vem carregada; nunca nas listas públicas.
            'station' => $this->whenLoaded('station', fn () => $this->station ? [
                'id' => $this->station->id,
                'name' => $this->station->name,
                'municipality' => $this->station->relationLoaded('municipality') ? $this->station->municipality?->name : null,
                'province' => $this->station->relationLoaded('province') ? $this->station->province?->name : null,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
