<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'user_id', 'station_id', 'municipality_id',
        'max_distance_km', 'channels', 'active', 'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
