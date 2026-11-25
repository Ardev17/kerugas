<?php

namespace App\Models;

use App\Enums\StationDataAccessEffect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Excepção por pessoa à regra do município, para a consulta dos postos.
 * Uma linha por utilizador; apagar a linha devolve a pessoa à regra geral.
 */
class StationDataGrant extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'effect', 'expires_at', 'reason', 'granted_by_id'];

    protected function casts(): array
    {
        return [
            'effect' => StationDataAccessEffect::class,
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    /** Uma excepção com prazo deixa de contar quando o prazo passa. */
    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** @param Builder<StationDataGrant> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
