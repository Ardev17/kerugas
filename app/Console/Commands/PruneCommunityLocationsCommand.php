<?php

namespace App\Console\Commands;

use App\Services\Community\CommunityLocationService;
use Illuminate\Console\Command;

/**
 * Vizinhos (Outubro 2026): apaga as coordenadas das partilhas de localização
 * que já acabaram (tempo escolhido, 18h, ou paradas pela pessoa). Corre a cada
 * 5 minutos — e a API, entretanto, já não as mostra a ninguém.
 */
class PruneCommunityLocationsCommand extends Command
{
    protected $signature = 'kerugas:community-locations-prune';

    protected $description = 'Apaga as coordenadas das partilhas de localização que já expiraram';

    public function handle(CommunityLocationService $locations): int
    {
        $this->info('Partilhas limpas: ' . $locations->prune());

        return self::SUCCESS;
    }
}
