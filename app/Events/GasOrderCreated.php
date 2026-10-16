<?php

namespace App\Events;

use App\Models\GasOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de DOMÍNIO "pedido criado" (avisa os Admins, etc.). Deixou de ser
 * ShouldBroadcast (Outubro 2026): o Laravel tenta transmitir um evento
 * ShouldBroadcast ANTES de correr os listeners — com o Reverb desligado (e
 * filas síncronas) a transmissão falhava e os listeners nunca corriam, ou
 * seja, os Admins ficavam sem aviso do pedido novo. O tempo real vive agora
 * em GasOrderCreatedBroadcast, enviado à parte e em "melhor esforço".
 */
class GasOrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly GasOrder $gasOrder)
    {
    }
}
