<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
