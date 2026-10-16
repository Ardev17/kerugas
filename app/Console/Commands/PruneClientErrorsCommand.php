<?php

namespace App\Console\Commands;

use App\Services\Monitoring\ClientErrorService;
use Illuminate\Console\Command;

/** Apaga relatórios de erros sem ocorrências há mais de 90 dias (Outubro 2026). */
class PruneClientErrorsCommand extends Command
{
    protected $signature = 'kerugas:client-errors-prune';

    protected $description = 'Apaga relatórios de erros antigos (90 dias)';

    public function handle(ClientErrorService $errors): int
    {
        $this->info('Relatórios apagados: ' . $errors->prune());

        return self::SUCCESS;
    }
}
