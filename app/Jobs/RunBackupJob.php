<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Faz uma cópia de segurança em segundo plano (Outubro 2026). Fila própria
 * ("backups"): uma cópia longa nunca atrasa SMS, notificações nem emails.
 * Uma só tentativa — se falhar, o Super Admin vê o motivo e carrega outra vez.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 28800; // 8 h

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onQueue('backups');
    }

    public function handle(BackupService $backups): void
    {
        $run = BackupRun::find($this->runId);
        if ($run) {
            $backups->run($run);
        }
    }

    public function failed(Throwable $e): void
    {
        BackupRun::whereKey($this->runId)->whereIn('status', [BackupRun::PENDING, BackupRun::RUNNING])->update([
            'status' => BackupRun::FAILED,
            'finished_at' => now(),
            'error' => mb_substr('A cópia foi interrompida: ' . $e->getMessage(), 0, 1000),
        ]);
    }
}
