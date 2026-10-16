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
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
