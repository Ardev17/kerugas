<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma instalação do app Android registada para receber notificações
 * push (ver a migration create_device_tokens_table para as regras).
 */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'personal_access_token_id', 'token', 'platform', 'app_version', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
