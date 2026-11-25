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
 * Job agendado (ver routes/console.php): percorre todos os postos ativos
 * e recalcula a confiança, para que a confiança decaia com o tempo mesmo
 * sem novo SMS ou confirmação (secção 30: a informação não pode ser
 * considerada eternamente válida).
 */
class ExpireStaleStationStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TrustScoreService $trustScoreService): void
    {
        Station::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('status_updated_at')
            ->chunkById(100, function ($stations) use ($trustScoreService) {
                foreach ($stations as $station) {
                    $newScore = $trustScoreService->calculate($station);

                    if ($newScore !== $station->current_confidence_score) {
                        $station->update(['current_confidence_score' => $newScore]);
                    }
                }
            });
    }
}
