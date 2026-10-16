<?php

namespace App\Console\Commands;

use App\Services\Campaigns\AutomaticCampaignRunner;
use Illuminate\Console\Command;

/**
 * Corre uma campanha automática agora. Chamado pelo agendador
 * (routes/console.php) na hora de cada campanha; também útil à mão:
 *   php artisan kerugas:campaign gas_reminder
 */
class RunCampaignCommand extends Command
{
    protected $signature = 'kerugas:campaign {key : Chave da campanha (ex: gas_reminder)}';

    protected $description = 'Corre uma campanha de notificações automática do KeruGás';

    public function handle(AutomaticCampaignRunner $runner): int
    {
        $key = (string) $this->argument('key');

        if (!in_array($key, AutomaticCampaignRunner::keys(), true)) {
            $this->error("Campanha desconhecida: {$key}. Disponíveis: " . implode(', ', AutomaticCampaignRunner::keys()));

            return self::FAILURE;
        }

        $sent = $runner->run($key);
        $this->info("{$key}: {$sent} notificação(ões) enviada(s).");

        return self::SUCCESS;
    }
}
