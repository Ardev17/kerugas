<?php

namespace App\Models;

use App\Enums\GasAvailability;
use App\Enums\QueueLevel;
use App\Enums\StationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Station extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'province_id', 'municipality_id',
        'address', 'reference_point', 'latitude', 'longitude',
        'phone', 'sms_phone', 'operator_id', 'analytics_enabled', 'opening_hours', 'photo_path', 'status',
        'current_cylinder_types',
        // REGRESSÃO CORRIGIDA (de novo): estes 4 campos são os que
        // StationStatusService::applyUpdate() e o dashboard do operador
        // atualizam a cada update de estado. Sem estarem aqui, o
        // Eloquent descarta-os SILENCIOSAMENTE em update()/create() —
        // sem erro nenhum — e o posto nunca reflete o novo estado. Este
        // é o mesmo bug já corrigido numa ronda anterior; se voltar a
        // aparecer, o que se perdeu foi este comentário e esta lista,
        // não a causa-raiz, que é sempre esta: um campo a mais na
        // migration do que no $fillable do Model.
        'current_gas_available', 'current_queue_level', 'current_confidence_score', 'status_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'status' => StationStatus::class,
            'current_gas_available' => 'boolean',
            'current_queue_level' => QueueLevel::class,
            'status_updated_at' => 'datetime',
            'analytics_enabled' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(StationStatusUpdate::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StationReview::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(StationComment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(StationReport::class);
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(StationConfirmation::class);
    }

    /**
     * Distância em km via fórmula de Haversine (uso em queries raw ->selectRaw()).
     * Mantido aqui apenas como referência de cálculo em PHP quando necessário fora do SQL.
     */
    public function distanceFromKm(float $lat, float $lng): float
    {
        $earthRadiusKm = 6371;

        $latFrom = deg2rad((float) $this->latitude);
        $lngFrom = deg2rad((float) $this->longitude);
        $latTo = deg2rad($lat);
        $lngTo = deg2rad($lng);

        $latDelta = $latTo - $latFrom;
        $lngDelta = $lngTo - $lngFrom;

        $angle = 2 * asin(sqrt(
            sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2
        ));

        return $angle * $earthRadiusKm;
    }
}
