<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensagem de uma conversa por sala (Outubro 2026): a conversa dos
 * condutores (room_type 'drivers', room_id = município) ou a "Conversa com
 * a Central" (room_type 'central', room_id = utilizador) ou a conversa
 * privada Super Admin ↔ investidor (room_type 'investor', room_id = investidor).
 */
class RoomMessage extends Model
{
    public const DRIVERS = 'drivers';

    public const CENTRAL = 'central';

    /** Conversa privada Super Admin ↔ investidor (room_id = investidor). */
    public const INVESTOR = 'investor';

    public const TYPES = [self::DRIVERS, self::CENTRAL, self::INVESTOR];

    protected $fillable = [
        'room_type', 'room_id', 'user_id', 'body', 'reply_to_id', 'image_path',
        'location_lat', 'location_lng', 'location_accuracy', 'location_expires_at', 'location_ended_at',
        'edited_at', 'removed_at', 'removed_by', 'removal_reason', 'author_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'location_lat' => 'float', 'location_lng' => 'float', 'location_accuracy' => 'integer',
            'location_expires_at' => 'datetime', 'location_ended_at' => 'datetime',
            'edited_at' => 'datetime', 'removed_at' => 'datetime', 'author_deleted_at' => 'datetime',
        ];
    }

    public function scopeRoom($query, string $type, int $id)
    {
        return $query->where('room_type', $type)->where('room_id', $id);
    }

    public function scopeVisible($query)
    {
        return $query->whereNull('removed_at')->whereNull('author_deleted_at');
    }

    public function isVisible(): bool
    {
        return $this->removed_at === null && $this->author_deleted_at === null;
    }

    public function hasLocation(): bool
    {
        return $this->location_lat !== null && $this->location_lng !== null;
    }

    public function locationActive(): bool
    {
        return $this->hasLocation() && $this->location_ended_at === null
            && $this->location_expires_at !== null && $this->location_expires_at->isFuture();
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
        return $this->hasMany(RoomMessageMention::class, 'message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(RoomMessageReaction::class, 'message_id');
    }
}
