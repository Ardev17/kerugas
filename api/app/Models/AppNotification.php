<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nomeado AppNotification (não "Notification") para evitar colisão com a
 * classe base de notificações do Laravel (Illuminate\Notifications\Notification).
 */
class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['user_id', 'category', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
