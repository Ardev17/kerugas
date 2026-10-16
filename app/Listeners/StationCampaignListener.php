<?php

namespace App\Listeners;

use App\Enums\QueueLevel;
use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Events\GasAvailabilityChanged;
use App\Models\Alert;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Campanhas disparadas por mudanças de estado de um posto (Setembro 2026):
 *
 * - "Há gás perto de si": o posto VOLTOU a ter gás (estava sem) → clientes
 *   do mesmo município. Quem já tem um alerta para este posto/município
 *   fica de fora — esse já recebe o alerta (DispatchStationAlertsListener),
 *   e receber os dois seria repetido.
 * - "Fila curta agora": com gás, a fila PASSOU a curta → quem tem o posto
 *   nos favoritos.
 *
 * Na fila e só depois do commit: um município pode ter milhares de
 * clientes, e a atualização do operador nunca pode ficar à espera disto.
 */
class StationCampaignListener implements ShouldQueue
{
    public bool $afterCommit = true;

    /** Abaixo do retry_after da fila (90 s); repetir é seguro (dedupe_key). */
    public int $timeout = 85;

    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    public function handle(GasAvailabilityChanged $event): void
    {
        $station = $event->station->fresh();
        if (!$station || $station->status !== StationStatus::ACTIVE) {
            return;
        }

        if ($event->wasAvailable === false) {
            $this->gasBackNearby($station);
        }

        $queueNow = $station->current_queue_level;
        if ($queueNow === QueueLevel::LOW && $event->previousQueue !== null && $event->previousQueue !== QueueLevel::LOW) {
            $this->shortQueue($station);
        }
    }

    private function gasBackNearby($station): void
    {
        $window = intdiv(now()->timestamp, 12 * 3600);

        User::query()
            ->where('role', UserRole::USER)
            ->where('status', 'ACTIVE')
            ->where('municipality_id', $station->municipality_id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new Alert())->getTable())
                ->whereColumn('alerts.user_id', 'users.id')
                ->where('alerts.active', true)
                ->where(fn ($w) => $w->where('alerts.station_id', $station->id)
                    ->orWhere('alerts.municipality_id', $station->municipality_id)))
            ->chunkById(200, function ($users) use ($station, $window) {
                foreach ($users as $user) {
                    $this->campaigns->deliver('gas_back_nearby', $user, [
                        'posto' => $station->name,
                        'municipio' => $station->municipality?->name ?? '',
                        'station_id' => $station->id,
                    ], "gas_back:{$user->id}:{$window}");
                }
            });
    }

    private function shortQueue($station): void
    {
        $today = now()->setTimezone($this->campaigns->timezone())->toDateString();

        User::query()
            ->where('role', UserRole::USER)
            ->where('status', 'ACTIVE')
            ->whereHas('favorites', fn (Builder $q) => $q->where('station_id', $station->id))
            ->chunkById(200, function ($users) use ($station, $today) {
                foreach ($users as $user) {
                    $this->campaigns->deliver('short_queue', $user, [
                        'posto' => $station->name,
                        'station_id' => $station->id,
                    ], "short_queue:{$user->id}:{$station->id}:{$today}");
                }
            });
    }
}
