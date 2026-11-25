<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipality extends Model
{
    use HasFactory;

    protected $fillable = [
        'province_id', 'name', 'slug', 'community_enabled',
        'station_data_blocked_from', 'station_data_blocked_reason', 'station_data_blocked_by_id',
    ];

    protected function casts(): array
    {
        return ['station_data_blocked_from' => 'datetime'];
    }

    /**
     * Consulta do estado dos postos fechada neste município (Novembro 2026).
     * Uma data no futuro é um bloqueio agendado: ainda não conta.
     */
    public function stationDataBlocked(): bool
    {
        return $this->station_data_blocked_from !== null
            && !$this->station_data_blocked_from->isFuture();
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stationDataBlockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'station_data_blocked_by_id');
    }
}
