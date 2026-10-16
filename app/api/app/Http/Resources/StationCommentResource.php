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
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
