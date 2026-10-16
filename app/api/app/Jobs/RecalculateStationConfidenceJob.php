<?php

namespace App\Jobs;

use App\Models\Station;
use App\Services\TrustScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalcula o score de confiança de um único posto. Disparado após
 * eventos que afetam a confiança (confirmações, denúncias) quando se
 * quer desacoplar o cálculo do request HTTP original.
 */
class RecalculateStationConfidenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(private readonly int $stationId)
    {
    }

    public function handle(TrustScoreService $trustScoreService): void
    {
        $station = Station::find($this->stationId);

        if (!$station) {
            return;
        }

        $station->update([
            'current_confidence_score' => $trustScoreService->calculate($station),
        ]);
    }
}
