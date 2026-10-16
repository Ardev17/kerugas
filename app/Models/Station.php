<?php

namespace App\Models;

use App\Enums\GasAvailability;
use App\Enums\QueueLevel;
use App\Enums\StationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Station extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'province_id', 'municipality_id',
        'address', 'reference_point', 'latitude', 'longitude',
        'phone', 'sms_phone', 'analytics_enabled', 'opening_hours', 'photo_path', 'status',
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

    /**
     * Número do posto (5 dígitos, sequencial): atribuído aqui, nunca escrito
     * à mão — por isso `code` NÃO está no $fillable.
     */
    protected static function booted(): void
    {
        // BUG CORRIGIDO (Outubro 2026): antes só se atribuía quando vinha vazio —
        // uma criação com `code` preenchido (ex.: factory, importação) ficava com
        // o número escolhido à mão. Agora é SEMPRE o seguinte da sequência, e
        // nunca se altera depois de criado.
        static::creating(function (Station $station) {
            $station->code = self::nextCode();
        });
        static::updating(function (Station $station) {
            if ($station->isDirty('code')) {
                $station->code = $station->getOriginal('code');
            }
        });
    }

    public const FIRST_CODE = 1;

    public static function nextCode(): string
    {
        // Query crua: inclui postos apagados (soft delete) — um número nunca se repete.
        $max = (int) \Illuminate\Support\Facades\DB::table('stations')->max('code');
        $next = max($max + 1, self::FIRST_CODE);
        if ($next > 99999) {
            throw new \RuntimeException('Os números de posto de 5 dígitos esgotaram-se.');
        }

        return str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

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

    /**
     * Operadores do posto (Outubro 2026): um posto pode ter vários, mas
     * cada operador pertence a um só posto (`users.operated_station_id`).
     */
    public function operators(): HasMany
    {
        return $this->hasMany(User::class, 'operated_station_id')->orderBy('id');
    }

    /** Operador principal (o mais antigo) — para avisos que vão a uma só pessoa. */
    public function operator(): HasOne
    {
        return $this->hasOne(User::class, 'operated_station_id')->oldestOfMany();
    }

    /** IDs de todos os operadores — para notificar a equipa inteira do posto. */
    public function operatorIds(): array
    {
        return User::where('operated_station_id', $this->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
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

        // min(1, ...): o arredondamento pode dar 1.0000000000000002 e o
        // sqrt/asin devolveriam NaN para dois pontos praticamente iguais.
        $angle = 2 * asin(min(1.0, sqrt(
            sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2
        )));

        return $angle * $earthRadiusKm;
    }
}
