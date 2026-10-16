<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensagem do Chat da Equipa (Outubro 2026). "Visível" = nem removida pelo
 * Super Admin nem apagada pelo autor.
 */
class StaffChatMessage extends Model
{
    protected $fillable = [
        'user_id', 'body', 'reply_to_id', 'image_path', 'pinned_at', 'pinned_by', 'edited_at',
        'removed_at', 'removed_by', 'removal_reason', 'author_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime', 'edited_at' => 'datetime',
            'removed_at' => 'datetime', 'author_deleted_at' => 'datetime',
        ];
    }

    public function scopeVisible($query)
    {
        return $query->whereNull('removed_at')->whereNull('author_deleted_at');
    }

    public function isVisible(): bool
    {
        return $this->removed_at === null && $this->author_deleted_at === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(StaffChatMention::class, 'message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StaffChatReaction::class, 'message_id');
    }
}
