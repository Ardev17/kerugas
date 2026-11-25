<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffChatMute extends Model
{
    protected $fillable = ['user_id', 'muted_by', 'reason', 'expires_at', 'lifted_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    public function scopeActive($query)
    {
        return $query->whereNull('lifted_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
