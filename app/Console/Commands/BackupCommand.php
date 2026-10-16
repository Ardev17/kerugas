<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * Cópia de segurança feita já, no próprio comando (Outubro 2026). É o que o
 * agendador usa para o backup automático noturno (BACKUP_SCHEDULE_ENABLED=true)
 * e serve também para um backup manual a partir do servidor.
 */
class BackupCommand extends Command
{
    protected $signature = 'kerugas:backup {--no-files : Só a base de dados, sem as fotos}';

    protected $description = 'Faz uma cópia de segurança da base de dados e das fotos';

    public function handle(BackupService $backups): int
    {
        $run = $backups->start(null, 'scheduled', $this->option('no-files') ? false : null, dispatch: false);
        if (!$run) {
            $this->warn('Já existe uma cópia a decorrer — nada feito.');

            return self::SUCCESS;
        }

        $run = $backups->run($run);
        if ($run->status !== 'completed') {
            $this->error('Falhou: ' . $run->error);

            return self::FAILURE;
        }

        $this->info(sprintf('Cópia concluída: %s (%s)', $run->db_file, number_format((int) $run->db_bytes / 1048576, 1) . ' MB'));
        if ($run->warning) {
            $this->warn($run->warning);
        }

        return self::SUCCESS;
    }
}
