<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationCounterReset extends Model
{
    protected $fillable = ['station_id', 'reset_by', 'bottles_before', 'reset_at'];

    protected function casts(): array
    {
        return ['reset_at' => 'datetime', 'bottles_before' => 'integer'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->withTrashed();
    }

    public function resetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by');
    }
}
