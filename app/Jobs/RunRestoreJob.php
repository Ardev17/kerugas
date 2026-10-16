<?php

namespace App\Jobs;

use App\Services\Backup\RestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Restauro em segundo plano (Outubro 2026), na fila "backups" (a mesma das
 * cópias: nunca correm as duas ao mesmo tempo). Uma só tentativa — repetir
 * sozinho um restauro seria perigoso. Sem modelos serializados: a base de
 * dados é apagada a meio.
 */
class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 28800; // 8 h

    public bool $failOnTimeout = true;

    public function __construct(public string $sessionId)
    {
        $this->onQueue('backups');
    }

    public function handle(RestoreService $restores): void
    {
        $restores->run($this->sessionId);
    }

    public function failed(Throwable $e): void
    {
        try {
            $restores = app(RestoreService::class);
            $status = $restores->status($this->sessionId);
            if (in_array($status['state'], ['queued', 'running'], true)) {
                $restores->patch($this->sessionId, [
                    'state' => 'failed',
                    'stage' => 'failed',
                    'error' => mb_substr('O restauro foi interrompido: ' . $e->getMessage(), 0, 1000),
                    'message' => 'O restauro foi interrompido. Se o site ficou em manutenção, no servidor corra: php artisan kerugas:restore ' . ($status['safety_file'] ?? 'latest'),
                    'finished_at' => now()->toIso8601String(),
                ]);
            }
        } catch (Throwable) {
            // sem estado para atualizar
        }
    }
}
