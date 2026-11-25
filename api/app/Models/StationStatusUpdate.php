<?php

namespace App\Models;

use App\Enums\QueueLevel;
use App\Enums\StationUpdateSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationStatusUpdate extends Model
{
    use HasFactory;

    protected $table = 'station_statuses';

    protected $fillable = [
        'station_id', 'gas_available', 'queue_level', 'source',
        'updated_by', 'confidence_score', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'gas_available' => 'boolean',
            'queue_level' => QueueLevel::class,
            'source' => StationUpdateSource::class,
            'expires_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
