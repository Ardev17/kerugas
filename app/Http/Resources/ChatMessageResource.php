<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'user_id' => $this->user_id,
            'user_avatar_url' => $this->whenLoaded('user', fn () => \App\Services\Media\AvatarService::url($this->user?->avatar_path)),
            'role' => $this->whenLoaded('user', fn () => $this->user?->role?->value),
            'province_id' => $this->province_id,
            'municipality_id' => $this->municipality_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
