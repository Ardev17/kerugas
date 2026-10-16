<?php

namespace App\Models;

use App\Enums\GasOrderPriority;
use App\Enums\GasOrderStatus;
use App\Enums\LocationSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GasOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_reference', 'user_id', 'customer_name', 'customer_phone',
        'province_id', 'municipality_id', 'neighborhood', 'address', 'reference_point',
        'latitude', 'longitude', 'location_source', 'location_accuracy',
        'quantity', 'cylinder_type', 'cylinder_types_confirmed', 'notes',
        'status', 'priority', 'assigned_to',
        'confirmed_at', 'contacted_at', 'completed_at', 'cancelled_at',
        'delivery_price', 'price_set_by', 'price_set_at',
        'price_corrected_by', 'price_corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'location_source' => LocationSource::class,
            'status' => GasOrderStatus::class,
            'priority' => GasOrderPriority::class,
            'confirmed_at' => 'datetime',
            'contacted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivery_price' => 'decimal:2',
            'price_set_at' => 'datetime',
            'price_corrected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function priceSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_set_by');
    }

    public function priceCorrectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_corrected_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GasOrderEvent::class);
    }

    /**
     * Avaliação do condutor (Setembro 2026) — no máximo uma por
     * pedido (`driver_ratings.gas_order_id` é única).
     */
    public function rating(): HasOne
    {
        return $this->hasOne(DriverRating::class, 'gas_order_id');
    }
}
