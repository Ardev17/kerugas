<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name ?? 'Conta eliminada'),
            'user_avatar_url' => $this->whenLoaded('user', fn () => \App\Services\Media\AvatarService::url($this->user?->avatar_path)),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
