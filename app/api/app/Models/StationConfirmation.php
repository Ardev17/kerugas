<?php

namespace App\Models;

use App\Enums\QueueLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationConfirmation extends Model
{
    use HasFactory;

    protected $fillable = ['station_id', 'user_id', 'gas_available', 'queue_level'];

    protected function casts(): array
    {
        return [
            'gas_available' => 'boolean',
            'queue_level' => QueueLevel::class,
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
