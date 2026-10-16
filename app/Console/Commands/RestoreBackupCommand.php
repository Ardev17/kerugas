<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Services\Backup\BackupService;
use App\Services\Backup\FilesRestorer;
use App\Services\Backup\SqlImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restaura uma cópia de segurança (Outubro 2026) — APAGA os dados atuais e
 * repõe os da cópia. Comando protegido de propósito (não há botão no painel:
 * um clique errado destruiria os dados e, com a base de dados destruída, o
 * painel nem funciona):
 *
 *  - confirma que o ficheiro está íntegro e completo ANTES de apagar nada;
 *  - mostra o que vai ser apagado e exige escrever o NOME da base de dados;
 *  - guarda uma cópia do estado atual ("pre-restauro-…") antes de apagar;
 *  - põe o site em manutenção durante o restauro;
 *  - `--database=outra` restaura numa base à parte (ensaio mensal), sem tocar
 *    no site.
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'kerugas:restore
        {file : Caminho do .sql.gz / .sql, só o nome (procura na pasta das cópias) ou "latest" (a mais recente)}
        {--files= : Caminho do .zip das fotos, só o nome, ou "latest"}
        {--database= : Restaurar NOUTRA base de dados (ensaio) em vez de na do site}
        {--confirm= : Nome da base de dados (para uso em scripts, em vez de o escrever)}
        {--no-safety-copy : Não guardar o estado atual antes de apagar}
        {--ignore-checksum : Aceitar um ficheiro cujo checksum não bate com o registado}';

    protected $description = 'Restaura uma cópia de segurança (APAGA os dados atuais)';

    public function handle(BackupService $backups): int
    {
        $live = !$this->option('database') || $this->option('database') === config('database.connections.' . config('database.default') . '.database');
        $config = $backups->connectionConfig(false, $live ? null : (string) $this->option('database'));
        $dbName = $config['database'];
        $importer = new SqlImporter($config);

        // 1. Ficheiros ---------------------------------------------------------------
        $file = $this->resolve((string) $this->argument('file'), 'kerugas-bd-*.sql.gz', $backups);
        if (!$file) {
            $this->error('Não encontrei o ficheiro da cópia: ' . $this->argument('file'));

            return self::FAILURE;
        }
        $filesZip = null;
        if ($this->option('files')) {
            $filesZip = $this->resolve((string) $this->option('files'), 'kerugas-fotos-*.zip', $backups);
            if (!$filesZip) {
                $this->error('Não encontrei o ficheiro das fotos: ' . $this->option('files'));

                return self::FAILURE;
            }
        }

        // 2. Verificação — antes de apagar seja o que for ----------------------------
        $this->line('A verificar o ficheiro (integridade e fim da cópia)…');
        try {
            $bytes = $importer->verify($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Nada foi apagado. Use outra cópia.');

            return self::FAILURE;
        }
        $this->info(sprintf('Ficheiro íntegro: %s (%s descomprimido).', basename($file), $this->human($bytes)));

        if (!$this->checksumOk($file)) {
            return self::FAILURE;
        }

        // 3. Confirmação -------------------------------------------------------------
        $this->newLine();
        $this->warn('ATENÇÃO — isto APAGA TODOS os dados atuais e repõe os da cópia.');
        $this->line(sprintf('  Base de dados: %s  (servidor %s)%s', $dbName, $config['host'] ?: 'socket', $live ? '  ← A DO SITE' : '  (ensaio, não é a do site)'));
        $this->line(sprintf('  Estado atual:  %s', $this->currentState($importer)));
        $this->line('  Cópia:         ' . basename($file));
        if ($filesZip) {
            $this->line('  Fotos:         ' . basename($filesZip));
        }
        $this->newLine();

        $typed = $this->option('confirm');
        if ($typed === null) {
            if (!$this->input->isInteractive()) {
                $this->error('Sem terminal para confirmar: use --confirm=' . $dbName . ' para autorizar.');

                return self::FAILURE;
            }
            $typed = $this->ask("Para continuar escreva o nome da base de dados ({$dbName})");
        }
        if ($typed !== $dbName) {
            $this->error('Confirmação errada — nada foi apagado.');

            return self::FAILURE;
        }

        // 4. Cópia de segurança do estado atual --------------------------------------
        $safety = null;
        if ($live && !$this->option('no-safety-copy')) {
            $safety = $this->safetyCopy($backups);
            if ($safety === false) {
                return self::FAILURE;
            }
        }

        // 5. Restauro ----------------------------------------------------------------
        if ($live) {
            $this->call('down', ['--retry' => 60]);
        }
        $started = microtime(true);
        try {
            if (!$live) {
                $importer->ensureDatabase();
            }
            $pdo = $importer->connect();
            $this->line('A apagar os dados atuais…');
            $tables = $importer->wipe($pdo);
            $this->line("  {$tables} tabelas/vistas apagadas.");

            $this->line('A repor a cópia (pode demorar)…');
            $statements = $importer->import($pdo, $file, fn (int $n) => $this->line("  {$n} instruções…"));
            $this->info("Base de dados reposta ({$statements} instruções, " . round(microtime(true) - $started) . ' s).');
            // Trabalhos em fila e caches gravados na cópia são velhos: corrê-los
            // de novo enviaria SMS/notificações antigas.
            \App\Services\Backup\RestoreService::clearTransientTables($pdo);

            if ($filesZip) {
                $restored = (new FilesRestorer())->restore($filesZip, $backups->filesRoot(), (array) config('backup.files_dirs'));
                $this->info("Fotos repostas: {$restored['restored']}" . ($restored['skipped'] ? " ({$restored['skipped']} ficheiros suspeitos ignorados)" : '') . '.');
            }
        } catch (Throwable $e) {
            $this->error('O restauro falhou: ' . $e->getMessage());
            $this->line('A base de dados pode ter ficado a meio. Corrija o problema e volte a correr o comando (ele apaga e recomeça do zero).');
            if ($safety) {
                $this->line("O estado anterior ao restauro está guardado em: {$safety}");
            }
            if ($live) {
                $this->warn('O site continua em manutenção. Depois de resolver, corra: php artisan up');
            }

            return self::FAILURE;
        }

        // 6. Depois ------------------------------------------------------------------
        if ($live) {
            $this->call('migrate', ['--force' => true]); // só aplica migrations mais novas do que a cópia
            foreach (['cache:clear', 'queue:restart'] as $command) {
                try {
                    $this->callSilently($command);
                } catch (Throwable $e) {
                    $this->warn("{$command}: {$e->getMessage()}");
                }
            }
            $this->call('up');

            try {
                AuditLog::create([
                    'user_id' => null, 'action' => 'backup.restored', 'entity_type' => 'backup_run', 'entity_id' => null,
                    'new_values' => ['file' => basename($file), 'with_files' => (bool) $filesZip],
                ]);
            } catch (Throwable) {
                // a cópia pode ser anterior à tabela de auditoria — não é motivo para falhar
            }
        }

        $this->newLine();
        $this->info('Restauro concluído. Estado atual: ' . $this->currentState($importer));
        $this->line('Verifique: entre no painel e confirme utilizadores, últimos pedidos e fotos.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ auxiliares

    private function resolve(string $arg, string $pattern, BackupService $backups): ?string
    {
        if ($arg === 'latest') {
            $matches = glob($backups->directory() . '/' . $pattern) ?: [];
            usort($matches, fn ($a, $b) => filemtime($b) <=> filemtime($a));

            return $matches[0] ?? null;
        }
        if (is_file($arg)) {
            return $arg;
        }
        $inDir = $backups->directory() . '/' . basename($arg);

        return is_file($inDir) ? $inDir : null;
    }

    /** Se esta cópia está registada, o checksum tem de bater. */
    private function checksumOk(string $file): bool
    {
        try {
            $run = BackupRun::where('db_file', basename($file))->first();
        } catch (Throwable) {
            return true; // base de dados vazia/destruída: não há registo para comparar
        }
        if (!$run || !$run->db_sha256) {
            return true;
        }
        if (hash_file('sha256', $file) === $run->db_sha256) {
            $this->info('Checksum confere com o registado.');

            return true;
        }
        if ($this->option('ignore-checksum')) {
            $this->warn('O checksum NÃO confere com o registado, mas foi aceite (--ignore-checksum).');

            return true;
        }
        $this->error('O checksum do ficheiro não confere com o registado quando a cópia foi feita (foi alterado ou danificado). Nada foi apagado.');
        $this->line('Se tem a certeza, use --ignore-checksum.');

        return false;
    }

    /** @return string|false|null caminho da cópia; false = abortar */
    private function safetyCopy(BackupService $backups): string|false|null
    {
        $path = $backups->directory() . '/pre-restauro-' . now()->format('Ymd-His') . '.sql.gz';
        $this->line('A guardar o estado atual antes de apagar…');
        try {
            $backups->dumper(false)->dump($path); // do servidor principal, nunca de uma réplica
            $this->info('Estado atual guardado em: ' . $path);

            return $path;
        } catch (Throwable $e) {
            $this->warn('Não foi possível guardar o estado atual: ' . $e->getMessage());
            if (!$this->input->isInteractive()) {
                $this->error('Para continuar sem essa cópia use --no-safety-copy.');

                return false;
            }
            if (!$this->confirm('Continuar mesmo sem a cópia do estado atual? (a base de dados pode estar corrompida)', false)) {
                $this->line('Nada foi apagado.');

                return false;
            }

            return null;
        }
    }

    private function currentState(SqlImporter $importer): string
    {
        try {
            $pdo = $importer->connect();
            $tables = $pdo->query('SHOW TABLES')->rowCount();
            $users = $tables ? (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;

            return "{$tables} tabelas, {$users} utilizadores";
        } catch (Throwable) {
            return 'vazia ou ilegível';
        }
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
