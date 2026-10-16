<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GasOrderEvent extends Model
{
    protected $fillable = [
        'gas_order_id', 'action', 'from_status', 'to_status', 'user_id', 'notes',
    ];

    public function gasOrder(): BelongsTo
    {
        return $this->belongsTo(GasOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
