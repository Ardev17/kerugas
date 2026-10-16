<?php

namespace App\Console\Commands;

use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use Illuminate\Console\Command;

/** Envios manuais agendados pelo Super Admin que já chegaram à hora. */
class DispatchDueBroadcastsCommand extends Command
{
    protected $signature = 'kerugas:broadcasts-due';

    protected $description = 'Dispara os envios manuais agendados cuja hora já chegou';

    public function handle(): int
    {
        Broadcast::where('status', 'SCHEDULED')
            ->where('scheduled_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) {
                // Marca como SENDING aqui, de forma atómica, para duas
                // execuções seguidas do agendador nunca o enviarem duas vezes.
                $claimed = Broadcast::where('id', $id)->where('status', 'SCHEDULED')->update(['status' => 'SENDING', 'started_at' => now()]);
                if ($claimed) {
                    SendBroadcastJob::dispatch($id);
                }
            });

        return self::SUCCESS;
    }
}
