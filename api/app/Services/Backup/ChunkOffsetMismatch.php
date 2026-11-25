<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * O painel enviou uma parte que não continua onde o servidor ficou (ligação
 * caiu a meio). A resposta diz quantos bytes já cá estão, para retomar dali.
 */
class ChunkOffsetMismatch extends RuntimeException
{
    public function __construct(public readonly int $received)
    {
        parent::__construct('A parte enviada não continua onde o envio ficou.');
    }
}
