<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensagem dos Vizinhos KeruGás (Outubro 2026).
 *
 * Estados (independentes):
 *  - `author_deleted_at`: o autor apagou — sai do chat, a moderação vê o registo;
 *  - `removed_at`: removida pela moderação (com motivo; reversível);
 *  - `hidden_at`: ocultada automaticamente por 3 denúncias, à espera de revisão.
 * "Visível no chat" = nenhum dos três.
 */
class CommunityMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'municipality_id', 'neighborhood_id', 'user_id', 'body', 'topic', 'reply_to_id', 'image_path',
        'official', 'pinned_at', 'pinned_by', 'edited_at', 'hidden_at', 'removed_at', 'removed_by',
        'removal_reason', 'author_deleted_at',
        // Partilha de localização (Outubro 2026) — ver CommunityLocationService.
        'location_kind', 'location_lat', 'location_lng', 'location_accuracy', 'location_station_id',
        'location_label', 'location_expires_at', 'location_ended_at',
    ];

    protected function casts(): array
    {
        return [
            'official' => 'boolean',
            'pinned_at' => 'datetime',
            'edited_at' => 'datetime',
            'hidden_at' => 'datetime',
            'removed_at' => 'datetime',
            'author_deleted_at' => 'datetime',
            'location_lat' => 'float',
            'location_lng' => 'float',
            'location_accuracy' => 'integer',
            'location_expires_at' => 'datetime',
            'location_ended_at' => 'datetime',
        ];
    }

    public function scopeVisible($query)
    {
        return $query->whereNull('removed_at')->whereNull('hidden_at')->whereNull('author_deleted_at');
    }

    public function isVisible(): bool
    {
        return $this->removed_at === null && $this->hidden_at === null && $this->author_deleted_at === null;
    }

    public function locationStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'location_station_id');
    }

    /** A partilha de localização ainda está a decorrer (e por isso as coordenadas podem ser mostradas). */
    public function locationActive(): bool
    {
        return $this->location_kind !== null
            && $this->location_ended_at === null
            && $this->location_expires_at !== null
            && $this->location_expires_at->isFuture();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(CommunityMention::class, 'message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommunityReaction::class, 'message_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommunityReport::class, 'message_id');
    }
}
