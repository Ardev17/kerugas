<?php

namespace App\Services;

use App\Enums\QueueLevel;
use App\Enums\StationUpdateSource;
use App\Events\GasAvailabilityChanged;
use App\Events\StationStatusUpdated;
use App\Models\Station;
use App\Models\StationStatusUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ponto único de aplicação de uma atualização de status de posto —
 * usado tanto pelo SmsProcessingService (código via SMS) quanto pelo
 * OperatorStationController (atualização manual no painel do operador).
 * Extraído para evitar duplicar a lógica de transação/evento/confiança
 * em dois sítios (secção 153: DRY).
 */
class StationStatusService
{
    public function __construct(private readonly TrustScoreService $trustScoreService)
    {
    }

    public function applyUpdate(
        Station $station,
        bool $gasAvailable,
        QueueLevel $queueLevel,
        StationUpdateSource $source,
        ?User $updatedBy = null,
    ): StationStatusUpdate {
        return DB::transaction(function () use ($station, $gasAvailable, $queueLevel, $source, $updatedBy) {
            // Estado anterior, antes de ser substituído — as campanhas
            // "Há gás perto de si"/"Fila curta" precisam dele (ver
            // GasAvailabilityChanged).
            $wasAvailable = (bool) $station->current_gas_available;
            $previousQueue = $station->current_queue_level instanceof QueueLevel
                ? $station->current_queue_level
                : QueueLevel::tryFrom((string) $station->current_queue_level);

            $statusUpdate = StationStatusUpdate::create([
                'station_id' => $station->id,
                'gas_available' => $gasAvailable,
                'queue_level' => $queueLevel,
                'source' => $source,
                'updated_by' => $updatedBy?->id,
                'confidence_score' => $source === StationUpdateSource::OFFICIAL_SMS ? 90 : 75,
                'expires_at' => now()->addHours(3),
            ]);

            $station->update([
                'current_gas_available' => $gasAvailable,
                'current_queue_level' => $queueLevel,
                'status_updated_at' => now(),
            ]);

            $station->refresh();
            $station->update([
                'current_confidence_score' => $this->trustScoreService->calculate($station),
            ]);

            // Defesa extra: se alguma cache de listagem de postos for
            // reintroduzida no futuro, nunca deve sobreviver a uma
            // atualização de estado real.
            Cache::forget('map:stations:active');

            // BUG CORRIGIDO: StationStatusUpdated implementa ShouldBroadcast.
            // Se BROADCAST_CONNECTION=reverb e o servidor Reverb não estiver
            // a correr (comum em desenvolvimento local), o despacho do
            // evento lança uma exceção de ligação que — com filas síncronas
            // (QUEUE_CONNECTION=sync, também comum em dev sem Redis) —
            // rebentava a transação toda com 500. O real-time é opcional
            // (Etapa 16): a sua ausência nunca deve impedir uma atualização
            // de estado de posto de ser guardada com sucesso.
            try {
                event(new StationStatusUpdated($station, $statusUpdate));
            } catch (Throwable $e) {
                Log::channel('single')->warning('[StationStatusService] Falha ao transmitir evento em tempo real (não crítico)', [
                    'station_id' => $station->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Alertas de "há gás" separados do tempo real (Outubro 2026): antes,
            // com o Reverb desligado, a falha acima saltava este bloco e os
            // clientes ficavam sem alertas.
            if ($gasAvailable) {
                try {
                    event(new GasAvailabilityChanged($station, $wasAvailable, $previousQueue));
                } catch (Throwable $e) {
                    Log::channel('single')->warning('[StationStatusService] Falha nos alertas de disponibilidade (não crítico)', [
                        'station_id' => $station->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $statusUpdate;
        });
    }
}
