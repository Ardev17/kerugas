<?php

namespace App\Models;

use App\Enums\SubscriptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um período de subscrição pago. Ver a migration para o modelo completo.
 *
 * Estado efetivo (`state`): CANCELLED (cancelada à mão), SCHEDULED
 * (começa no futuro), ACTIVE (a cobrir agora) ou EXPIRED (já passou).
 * Só ACTIVE conta como "subscrito".
 */
class Subscription extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'type', 'phone', 'station_id', 'status', 'starts_at', 'expires_at', 'months',
        'amount_kz', 'payment_reference', 'notes', 'activated_by',
        'cancelled_at', 'cancelled_by', 'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SubscriptionType::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'months' => 'integer',
            'amount_kz' => 'integer',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /** Subscrições que cobrem o instante dado. */
    public function scopeCovering(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', $at)
            ->where('expires_at', '>', $at);
    }

    public function getStateAttribute(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return 'CANCELLED';
        }
        if ($this->expires_at->lte(now())) {
            return 'EXPIRED';
        }
        if ($this->starts_at->gt(now())) {
            return 'SCHEDULED';
        }

        return 'ACTIVE';
    }
}
