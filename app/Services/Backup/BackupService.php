<?php

namespace App\Services\Backup;

use App\Jobs\RunBackupJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Cópias de segurança (Outubro 2026) — o botão do Super Admin geral.
 *
 * `start()` regista o pedido e põe-no na fila (a resposta ao clique é
 * imediata; a cópia pode demorar minutos ou horas). `run()` faz o trabalho:
 * base de dados (.sql.gz) + fotos (.zip) na pasta de backups, e apaga as
 * cópias mais antigas além do limite guardado (BACKUP_KEEP).
 */
class BackupService
{
    /** Já há uma cópia a decorrer. */
    public const E_ACTIVE = 'backup_active';

    public function directory(): string
    {
        $dir = (string) config('backup.path');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta das cópias de segurança.');
        }

        return rtrim($dir, '/\\');
    }

    /** Pede uma cópia nova e põe-na na fila. Devolve null se já houver uma a decorrer. */
    public function start(?User $user, string $trigger = 'manual', ?bool $includeFiles = null, bool $dispatch = true): ?BackupRun
    {
        $lock = Cache::lock('kerugas:backup:start', 10);
        $lock->block(5);

        try {
            $this->failStale();
            if (BackupRun::whereIn('status', [BackupRun::PENDING, BackupRun::RUNNING])->exists()) {
                return null;
            }

            $run = BackupRun::create([
                'user_id' => $user?->id,
                'trigger' => $trigger,
                'status' => BackupRun::PENDING,
                'include_files' => $includeFiles ?? (bool) config('backup.include_files'),
            ]);
        } finally {
            $lock->release();
        }

        $this->audit($user, 'backup.started', $run, ['trigger' => $trigger, 'include_files' => $run->include_files]);
        if ($dispatch) {
            RunBackupJob::dispatch($run->id);
        }

        return $run;
    }

    /** Faz a cópia. Nunca lança erro: um problema fica registado na própria cópia. */
    public function run(BackupRun $run): BackupRun
    {
        if ($run->status !== BackupRun::PENDING) {
            return $run; // já tratada (ex.: o mesmo job entregue duas vezes)
        }
        $run->forceFill(['status' => BackupRun::RUNNING, 'started_at' => now()])->save();

        $dbPath = $filesPath = null;
        try {
            $dir = $this->directory();
            $this->assertFreeSpace($dir);

            $stamp = now()->format('Ymd-His') . '-' . $run->id;
            $dbName = "kerugas-bd-{$stamp}.sql.gz";
            $dbPath = "{$dir}/{$dbName}";
            // Vizinhos: as coordenadas de partilhas de localização que já acabaram
            // nunca vão para uma cópia. (A cópia automática da noite nem apanha
            // partilhas ativas: a partilha só existe entre as 08h e as 18h.)
            try {
                app(\App\Services\Community\CommunityLocationService::class)->prune();
            } catch (\Throwable) {
                // a cópia continua mesmo que a limpeza falhe
            }
            $dump = $this->dumper()->dump($dbPath);

            $run->forceFill([
                'db_file' => $dbName,
                'db_bytes' => $dump['bytes'],
                'db_sha256' => $dump['sha256'],
                'warning' => $dump['note'],
            ])->save();

            // As fotos são um extra: se falharem, a cópia da base de dados
            // (o mais importante) continua válida — fica só um aviso.
            if ($run->include_files) {
                try {
                    $filesName = "kerugas-fotos-{$stamp}.zip";
                    $filesPath = "{$dir}/{$filesName}";
                    $archived = (new FilesArchiver())->archive(
                        $this->filesRoot(),
                        (array) config('backup.files_dirs'),
                        $filesPath,
                    );
                    if ($archived) {
                        $run->forceFill(['files_file' => $filesName, 'files_bytes' => $archived['bytes'], 'files_count' => $archived['count']])->save();
                    } else {
                        $filesPath = null;
                        $run->forceFill(['files_count' => 0])->save();
                    }
                } catch (Throwable $e) {
                    $filesPath = null;
                    $run->forceFill(['warning' => trim(($run->warning ? $run->warning . ' ' : '') . 'Fotos não incluídas: ' . $e->getMessage())])->save();
                    Log::channel('single')->warning('[Backup] fotos não incluídas', ['run' => $run->id, 'error' => $e->getMessage()]);
                }
            }

            $run->forceFill(['status' => BackupRun::COMPLETED, 'finished_at' => now()])->save();
            $this->audit($run->user, 'backup.completed', $run, [
                'db_bytes' => $run->db_bytes, 'files_bytes' => $run->files_bytes, 'files_count' => $run->files_count,
            ]);
            $this->prune();
        } catch (Throwable $e) {
            foreach ([$dbPath, $filesPath] as $path) {
                if ($path) {
                    @unlink($path);
                    @unlink($path . '.part');
                }
            }
            $run->forceFill([
                'status' => BackupRun::FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'db_file' => null, 'db_bytes' => null, 'db_sha256' => null, 'files_file' => null, 'files_bytes' => null, 'files_count' => null,
            ])->save();
            $this->audit($run->user, 'backup.failed', $run, ['error' => $run->error]);
            Log::channel('single')->error('[Backup] falhou', ['run' => $run->id, 'error' => $e->getMessage()]);
        }

        return $run->refresh();
    }

    /** Dá como falhadas as cópias "em curso" há demasiado tempo (servidor reiniciado a meio). */
    public function failStale(): void
    {
        BackupRun::whereIn('status', [BackupRun::PENDING, BackupRun::RUNNING])
            ->where('created_at', '<', now()->subMinutes((int) config('backup.stale_after_minutes')))
            ->update([
                'status' => BackupRun::FAILED,
                'finished_at' => now(),
                'error' => 'A cópia não terminou (o servidor pode ter reiniciado a meio).',
            ]);
    }

    /** Mantém só as N cópias concluídas mais recentes. Devolve quantas apagou. */
    public function prune(): int
    {
        $keep = (int) config('backup.keep');
        if ($keep <= 0) {
            return 0;
        }

        $old = BackupRun::where('status', BackupRun::COMPLETED)->whereNull('purged_at')
            ->orderByDesc('id')->skip($keep)->take(1000)->get();
        foreach ($old as $run) {
            $this->purge($run);
        }

        return $old->count();
    }

    /** Apaga os ficheiros de uma cópia (a linha fica no histórico). */
    public function purge(BackupRun $run): void
    {
        foreach ([$run->db_file, $run->files_file] as $name) {
            if ($name) {
                @unlink($this->directory() . '/' . basename($name));
            }
        }
        $run->forceFill(['purged_at' => now()])->save();
    }

    /** Caminho (seguro) de um ficheiro da cópia, ou null se já não existir. */
    public function path(BackupRun $run, string $kind): ?string
    {
        $name = $kind === 'files' ? $run->files_file : ($kind === 'db' ? $run->db_file : null);
        if (!$name || !$run->isDownloadable()) {
            return null;
        }
        $path = $this->directory() . '/' . basename($name); // basename: nunca sai da pasta
        $path = is_file($path) ? $path : null;

        return $path;
    }

    /** Link de descarga que expira em poucos minutos (BACKUP_DOWNLOAD_LINK_MINUTES). */
    public function signedUrl(BackupRun $run, string $kind): string
    {
        return URL::temporarySignedRoute(
            'backups.download',
            now()->addMinutes((int) config('backup.download_link_minutes')),
            ['backupRun' => $run->id, 'kind' => $kind],
        );
    }

    public function freeBytes(): ?int
    {
        try {
            $free = @disk_free_space($this->directory());

            return $free === false ? null : (int) $free;
        } catch (Throwable) {
            return null;
        }
    }

    public function audit(?User $user, string $action, BackupRun $run, array $new = []): void
    {
        try {
            AuditLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'entity_type' => 'backup_run',
                'entity_id' => $run->id,
                'new_values' => $new,
                'ip_address' => request()?->ip(),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 250),
            ]);
        } catch (Throwable $e) {
            Log::channel('single')->warning('[Backup] auditoria falhou', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ internos

    public function dumper(bool $readReplica = true): DatabaseDumper
    {
        if (app()->bound(DatabaseDumper::class)) {
            return app(DatabaseDumper::class);
        }

        return new DatabaseDumper($this->connectionConfig($readReplica), (string) config('backup.mysqldump'));
    }

    /**
     * Dados de ligação à base de dados. Com `$readReplica` usa as variáveis
     * BACKUP_DB_* (réplica de leitura) quando existirem — certo para COPIAR,
     * nunca para RESTAURAR (que tem de ir sempre ao servidor principal).
     *
     * @return array{host:?string,port:int|string|null,database:string,username:string,password:string,unix_socket:?string}
     */
    public function connectionConfig(bool $readReplica = false, ?string $database = null): array
    {
        $db = (array) config('database.connections.' . config('database.default'));
        $override = $readReplica ? array_filter((array) config('backup.db'), fn ($v) => $v !== null && $v !== '') : [];

        return [
            'host' => $override['host'] ?? $db['host'] ?? null,
            'port' => $override['port'] ?? $db['port'] ?? null,
            'database' => $database ?? (string) ($db['database'] ?? ''),
            'username' => (string) ($override['username'] ?? $db['username'] ?? ''),
            'password' => (string) ($override['password'] ?? $db['password'] ?? ''),
            'unix_socket' => $db['unix_socket'] ?? null,
        ];
    }

    /** Raiz onde o disco "local" (fotos) guarda os ficheiros. */
    public function filesRoot(): string
    {
        return (string) \Illuminate\Support\Facades\Storage::disk('local')->path('');
    }

    private function assertFreeSpace(string $dir): void
    {
        $free = @disk_free_space($dir);
        $min = (int) config('backup.min_free_mb') * 1024 * 1024;
        if ($free !== false && $free < $min) {
            throw new RuntimeException(sprintf('Pouco espaço livre no disco das cópias (%d MB). Liberte espaço ou mude BACKUP_PATH.', (int) ($free / 1024 / 1024)));
        }
    }
}
