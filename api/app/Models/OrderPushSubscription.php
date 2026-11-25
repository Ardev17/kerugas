<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Navegador de um cliente SEM conta, inscrito nos avisos de um pedido (Outubro 2026). */
class OrderPushSubscription extends Model
{
    protected $fillable = [
        'gas_order_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token',
        'content_encoding', 'user_agent',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(GasOrder::class, 'gas_order_id');
    }
}
