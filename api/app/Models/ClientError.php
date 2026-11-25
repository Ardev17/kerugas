<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Erro do site/app agrupado por fingerprint (Outubro 2026). */
class ClientError extends Model
{
    protected $fillable = [
        'fingerprint', 'source', 'name', 'message', 'stack', 'url_path', 'app_version',
        'user_agent', 'last_user_id', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    public function lastUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_user_id');
    }
}
