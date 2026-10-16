<?php

namespace App\Services;

use App\Enums\StationUpdateSource;
use App\Models\Station;
use Carbon\Carbon;

/**
 * Calcula um score de confiança (0-100) para o estado atual de um posto,
 * combinando: atualidade, fonte da informação, confirmações da comunidade
 * e denúncias recentes.
 *
 * Este serviço NUNCA declara "100% garantido" — apenas níveis relativos
 * de confiança, exibidos como "alta" / "moderada" / "desatualizada".
 */
class TrustScoreService
{
    private const SOURCE_WEIGHTS = [
        StationUpdateSource::OFFICIAL_SMS->value => 40,
        StationUpdateSource::OPERATOR->value => 35,
        StationUpdateSource::ADMIN->value => 30,
        StationUpdateSource::COMMUNITY->value => 15,
    ];

    public function calculate(Station $station): int
    {
        if (!$station->status_updated_at) {
            return 0;
        }

        $score = 0;

        // Peso pela fonte da última atualização (até 40 pontos)
        $source = $station->statuses()->latest()->first()?->source?->value;
        $score += self::SOURCE_WEIGHTS[$source] ?? 10;

        // Peso pela atualidade (até 40 pontos)
        $minutesAgo = Carbon::parse($station->status_updated_at)->diffInMinutes(now());
        $score += match (true) {
            $minutesAgo <= 15 => 40,
            $minutesAgo <= 60 => 28,
            $minutesAgo <= 180 => 12,
            default => 0,
        };

        // Peso por confirmações recentes da comunidade nas últimas 3h (até 15 pontos)
        $confirmations = $station->confirmations()
            ->where('created_at', '>=', now()->subHours(3))
            ->count();
        $score += min($confirmations * 3, 15);

        // Penalização por denúncias abertas recentes (até -20 pontos)
        $openReports = $station->reports()
            ->where('status', 'OPEN')
            ->where('created_at', '>=', now()->subHours(6))
            ->count();
        $score -= min($openReports * 5, 20);

        return max(0, min(100, $score));
    }

    public function label(int $score): string
    {
        return match (true) {
            $score >= 70 => 'Confiança alta',
            $score >= 40 => 'Confiança moderada',
            default => 'Informação desatualizada',
        };
    }
}
