<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A consulta dos postos de um município voltou a ser grátis (Novembro 2026):
 * avisa os clientes de lá. Em fila, porque um município pode ter milhares de
 * clientes e o pedido do administrador não deve esperar por isso.
 */
class NotifyMunicipalityReopenedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $municipalityId, public readonly string $stamp)
    {
    }

    public function handle(CampaignService $campaigns): void
    {
        $municipality = Municipality::find($this->municipalityId);
        if (!$municipality || $municipality->stationDataBlocked()) {
            return; // entretanto voltou a fechar
        }

        User::query()
            ->where('role', UserRole::USER)->where('status', 'ACTIVE')
            ->where('municipality_id', $municipality->id)
            ->chunkById(200, function ($users) use ($campaigns, $municipality) {
                foreach ($users as $user) {
                    $campaigns->deliver('municipality_free_again', $user, ['municipio' => $municipality->name],
                        "municipality_free_again:{$user->id}:{$municipality->id}:{$this->stamp}");
                }
            });
    }
}
