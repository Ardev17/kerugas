<?php

namespace App\Services;

use App\Enums\FreshnessLevel;
use Carbon\Carbon;

/**
 * Determina o nível de "frescura" (freshness) de uma atualização, com
 * janelas configuráveis via config/kerugas.php — nunca hardcoded de
 * forma definitiva, conforme regra de negócio do produto.
 */
class FreshnessService
{
    public function level(?Carbon $updatedAt): FreshnessLevel
    {
        if ($updatedAt === null) {
            return FreshnessLevel::EXPIRED;
        }

        $minutes = $updatedAt->diffInMinutes(now());

        $windows = config('kerugas.freshness_windows');

        return match (true) {
            $minutes <= $windows['fresh_max_minutes'] => FreshnessLevel::FRESH,
            $minutes <= $windows['recent_max_minutes'] => FreshnessLevel::RECENT,
            $minutes <= $windows['stale_max_minutes'] => FreshnessLevel::STALE,
            default => FreshnessLevel::EXPIRED,
        };
    }
}
