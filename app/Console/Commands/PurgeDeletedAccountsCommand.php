<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Eliminação de conta (Outubro 2026):
 *   php artisan kerugas:accounts-purge
 * Anonimiza as contas cujo prazo de arrependimento já passou. Uma conta
 * que entretanto ficou com algo por acertar (ex.: garrafas) fica para o
 * dia seguinte.
 */
class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'kerugas:accounts-purge';

    protected $description = 'Anonimiza as contas com eliminação pedida e prazo terminado';

    public function handle(AccountDeletionService $deletion): int
    {
        $done = 0;
        $skipped = 0;

        User::query()->whereNotNull('deletion_scheduled_for')->where('deletion_scheduled_for', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($deletion, &$done, &$skipped) {
                foreach ($users as $user) {
                    try {
                        $deletion->purge($user) ? $done++ : $skipped++;
                    } catch (Throwable $e) {
                        $skipped++;
                        report($e);
                    }
                }
            });

        $this->info("Contas eliminadas: {$done}. Adiadas: {$skipped}.");

        return self::SUCCESS;
    }
}
