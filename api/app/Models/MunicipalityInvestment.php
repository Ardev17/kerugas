<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um período em que um investidor tem uma percentagem num município
 * (Outubro 2026). `ends_at` null = em vigor. Ver InvestorService.
 */
class MunicipalityInvestment extends Model
{
    protected $fillable = ['municipality_id', 'user_id', 'percentage', 'starts_at', 'ends_at', 'end_reason', 'assigned_by', 'ended_by'];

    protected function casts(): array
    {
        return ['percentage' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ends_at');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
