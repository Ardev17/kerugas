<?php

namespace App\Events;

use App\Enums\QueueLevel;
use App\Models\Station;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GasAvailabilityChanged
{
    use Dispatchable, SerializesModels;

    /**
     * `$wasAvailable` / `$previousQueue` (Setembro 2026): o estado ANTES
     * desta atualização. Sem eles, as campanhas "Há gás perto de si" e
     * "Fila curta agora" não conseguiam distinguir "voltou a ter gás" de
     * "continua com gás" — e notificariam a cada atualização. Opcionais
     * (null) para não partir quem já disparava este evento sem eles.
     */
    public function __construct(
        public readonly Station $station,
        public readonly ?bool $wasAvailable = null,
        public readonly ?QueueLevel $previousQueue = null,
    ) {
    }
}
