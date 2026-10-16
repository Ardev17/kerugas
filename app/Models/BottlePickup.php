<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Carregamento de garrafas num posto — ver a migration 2026_10_05_000003. */
class BottlePickup extends Model
{
    protected $fillable = [
        'client_uuid', 'station_id', 'driver_id', 'quantity', 'amount', 'registered_at', 'synced_offline',
        'confirmed_at', 'confirmed_by', 'disputed_at', 'disputed_by', 'dispute_note',
        'station_paid_at', 'station_paid_by',
        'source', 'registered_by', 'central_channel', 'central_reason', 'needs_confirmation',
    ];

    public const SOURCE_APP = 'APP';
    public const SOURCE_CENTRAL = 'CENTRAL';
    public const SOURCE_SMS = 'SMS';
    public const CHANNELS = ['PHONE', 'IN_PERSON', 'SMS', 'OTHER'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount' => 'decimal:2',
            'registered_at' => 'datetime',
            'synced_offline' => 'boolean',
            'needs_confirmation' => 'boolean',
            'confirmed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'station_paid_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** Precisa de confirmação (registo da central, ou SMS com PIN provisório) e ainda sem ninguém (Super Admin, condutor ou posto) a confirmar. */
    public function awaitingConfirmation(): bool
    {
        return (bool) $this->needs_confirmation && !$this->confirmed_at && !$this->disputed_at;
    }

    /**
     * Conta para pagar ao posto? Os registos da central só depois de
     * confirmados (Super Admin, condutor ou posto).
     */
    public function scopePayable($q)
    {
        return $q->where(fn ($w) => $w->where('needs_confirmation', false)->orWhereNotNull('confirmed_at'));
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** CONFIRMED (operador confirmou), DISPUTED (operador contestou) ou DRIVER_ONLY. */
    public function confirmationState(): string
    {
        if ($this->disputed_at) {
            return 'DISPUTED';
        }

        return $this->confirmed_at ? 'CONFIRMED' : 'DRIVER_ONLY';
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'station_id' => $this->station_id,
            'station_name' => $this->station?->name,
            'station_code' => $this->station?->code,
            'source' => $this->source ?? self::SOURCE_APP,
            'registered_by_id' => $this->source !== self::SOURCE_APP ? $this->registered_by : null,
            'registered_by_name' => $this->source !== self::SOURCE_APP ? $this->registrar?->name : null,
            'central_channel' => $this->central_channel,
            'central_reason' => $this->central_reason,
            'needs_confirmation' => (bool) $this->needs_confirmation,
            'awaiting_confirmation' => $this->awaitingConfirmation(),
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'quantity' => (int) $this->quantity,
            'amount' => (float) $this->amount,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'synced_offline' => (bool) $this->synced_offline,
            'state' => $this->confirmationState(),
            'confirmed_by' => $this->confirmer?->name,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'dispute_note' => $this->dispute_note,
            'station_paid_at' => $this->station_paid_at?->toIso8601String(),
        ];
    }
}
