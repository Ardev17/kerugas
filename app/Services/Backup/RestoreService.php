<?php

namespace App\Services\Backup;

use App\Jobs\RunRestoreJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Restauro a partir do painel (Outubro 2026) — botão "Restaurar" do Super
 * Admin geral. APAGA os dados atuais e repõe os de uma cópia.
 *
 * Fluxo:
 *  1. `create()` abre uma sessão (pasta própria em BACKUP_RESTORE_PATH);
 *  2. o painel envia o .sql/.sql.gz e as fotos (o .zip da cópia OU as pastas
 *     avatars/ e community/) em partes de 1 MB (`appendChunk`) — retoma onde
 *     ficou se a ligação cair;
 *  3. `verify()` confirma que a cópia está inteira ANTES de se pedir a
 *     confirmação (nada é apagado até aqui);
 *  4. `start()` (palavra-passe + "RESTAURAR") põe o trabalho na fila;
 *  5. `run()` (na fila "backups"): guarda o estado atual, põe o site em
 *     manutenção, apaga, importa, repõe as fotos, aplica migrations e volta
 *     a abrir. Se a importação falhar, REPÕE SOZINHO o estado anterior.
 *
 * O estado vive num ficheiro (status.json), não na base de dados — a base é
 * apagada a meio do restauro. O painel lê-o por um link assinado que não
 * precisa de sessão (os tokens de sessão também são substituídos).
 */
class RestoreService
{
    public const CONFIRM_WORD = 'RESTAURAR';

    /** Extensões aceites nas pastas de fotos. */
    private const PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(private BackupService $backups) {}

    // ================================================================== sessões

    public function root(): string
    {
        $dir = rtrim((string) config('backup.restore_path'), '/\\');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de restauro.');
        }

        return $dir;
    }

    /** Pasta de uma sessão — o id só tem letras/números (nunca sai da raiz). */
    public function dir(string $id): string
    {
        if (!preg_match('/^[a-z0-9]{24}$/', $id)) {
            throw new RuntimeException('Sessão de restauro inválida.');
        }

        return $this->root() . '/' . $id;
    }

    public function exists(string $id): bool
    {
        try {
            return is_file($this->dir($id) . '/status.json');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Abre uma sessão nova. Devolve null se já houver um restauro (ou uma
     * cópia) a decorrer.
     */
    public function create(User $user, string $source = 'upload', ?BackupRun $run = null, bool $withFiles = false): ?string
    {
        return $this->withLock(function () use ($user, $source, $run, $withFiles) {
            $this->cleanup();
            if ($this->activeSession() || BackupRun::whereIn('status', [BackupRun::PENDING, BackupRun::RUNNING])->exists()) {
                return null;
            }

            $id = strtolower(Str::random(24)); // só letras e números
            @mkdir($this->root() . '/' . $id . '/files', 0750, true);
            $this->write($id, [
                'id' => $id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'source' => $source,
                'backup_run_id' => $run?->id,
                'with_files' => $withFiles,
                'state' => $source === 'upload' ? 'uploading' : 'ready',
                'stage' => null,
                'message' => null,
                'error' => null,
                'progress' => 0,
                'summary' => null,
                'safety_file' => null,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ]);

            return $id;
        });
    }

    /** Estado (lido do ficheiro). */
    public function status(string $id): array
    {
        $file = $this->dir($id) . '/status.json';
        $raw = is_file($file) ? (string) file_get_contents($file) : '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Sessão de restauro não encontrada.');
        }

        return $data;
    }

    /** Link (assinado, 24 h) para o painel acompanhar o restauro sem sessão. */
    public function statusUrl(string $id): string
    {
        return URL::temporarySignedRoute('restores.status', now()->addDay(), ['restore' => $id]);
    }

    public function cancel(string $id): void
    {
        $status = $this->status($id);
        if (in_array($status['state'], ['queued', 'running'], true)) {
            throw new RuntimeException('O restauro já começou e não pode ser cancelado.');
        }
        $this->deleteDir($this->dir($id));
    }

    // ================================================================== envio

    /**
     * Acrescenta uma parte a um ficheiro da sessão.
     *
     * @param 'sql'|'zip'|'photo' $kind
     * @return array{received:int, complete:bool}
     */
    public function appendChunk(string $id, string $kind, string $name, int $offset, bool $final, UploadedFile $chunk): array
    {
        $status = $this->status($id);
        if ($status['state'] !== 'uploading' && $status['state'] !== 'verified') {
            throw new RuntimeException('Esta sessão já não aceita ficheiros.');
        }

        $target = $this->targetFor($id, $kind, $name);
        $part = $target . '.part';
        if (!is_dir(dirname($part)) && !@mkdir(dirname($part), 0750, true) && !is_dir(dirname($part))) {
            throw new RuntimeException('Não foi possível guardar o ficheiro.');
        }

        // Retomar: o cliente diz de onde continua; tem de bater com o que já cá está.
        if ($offset === 0 && is_file($part)) {
            @unlink($part);
        }
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($have !== $offset) {
            throw new ChunkOffsetMismatch($have);
        }

        $size = (int) $chunk->getSize();
        $this->assertSpace($size);
        $max = (int) config('backup.restore_max_upload_mb') * 1024 * 1024;
        if ($max > 0 && $offset + $size > $max) {
            throw new RuntimeException('O ficheiro é maior do que o limite aceite (BACKUP_RESTORE_MAX_UPLOAD_MB).');
        }
        if ($kind === 'photo' && $offset + $size > 15 * 1024 * 1024) {
            throw new RuntimeException('Foto demasiado grande: ' . $name);
        }

        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($part, 'ab');
        if (!$in || !$out) {
            throw new RuntimeException('Não foi possível guardar o ficheiro.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $part);
        $received = (int) filesize($part);

        if ($final) {
            if ($kind === 'sql') {
                // Só pode haver um ficheiro da base de dados por sessão.
                foreach (['db.sql', 'db.sql.gz'] as $other) {
                    $o = $this->dir($id) . '/' . $other;
                    if ($o !== $target && is_file($o)) {
                        @unlink($o);
                    }
                }
            }
            if (!@rename($part, $target)) {
                throw new RuntimeException('Não foi possível guardar o ficheiro.');
            }
            // Ficheiros mudaram: é preciso verificar de novo.
            if ($status['state'] === 'verified') {
                $this->patch($id, ['state' => 'uploading', 'summary' => null]);
            }
        }

        return ['received' => $received, 'complete' => $final];
    }

    /** Caminho final de um ficheiro enviado — validado (sem `..`, só pastas/extensões esperadas). */
    public function targetFor(string $id, string $kind, string $name): string
    {
        $dir = $this->dir($id);

        return match ($kind) {
            'sql' => $dir . '/' . (str_ends_with(strtolower($name), '.sql.gz') ? 'db.sql.gz' : (str_ends_with(strtolower($name), '.sql') ? 'db.sql' : throw new RuntimeException('O ficheiro da base de dados tem de ser .sql ou .sql.gz.'))),
            'zip' => str_ends_with(strtolower($name), '.zip') ? $dir . '/photos.zip' : throw new RuntimeException('O ficheiro das fotos tem de ser .zip.'),
            'photo' => $dir . '/files/' . self::photoPath($name),
            default => throw new RuntimeException('Tipo de ficheiro desconhecido.'),
        };
    }

    /**
     * Caminho relativo seguro de uma foto de uma pasta escolhida no painel:
     * "fotos/avatars/ab.webp" → "avatars/ab.webp". Só avatars/ e community/.
     */
    public static function photoPath(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $parts = array_values(array_filter(explode('/', $name), fn ($p) => $p !== '' && $p !== '.'));
        $dirs = (array) config('backup.files_dirs', ['avatars', 'community']);
        $start = null;
        foreach ($parts as $i => $p) {
            if (in_array($p, $dirs, true)) {
                $start = $i; // a ÚLTIMA pasta conhecida no caminho
            }
        }
        if ($start === null || $start === count($parts) - 1) {
            throw new RuntimeException('A foto tem de estar dentro da pasta avatars ou community: ' . $name);
        }
        $rel = implode('/', array_slice($parts, $start));
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel) || !in_array($ext, self::PHOTO_EXT, true)) {
            throw new RuntimeException('Nome de foto não aceite: ' . $name);
        }

        return $rel;
    }

    // ================================================================== verificação

    /**
     * Confirma que a cópia está inteira e resume o que vai ser reposto.
     * Nada é apagado aqui.
     */
    public function verify(string $id): array
    {
        $paths = $this->paths($id);
        if (!$paths['db']) {
            throw new RuntimeException('Falta o ficheiro da base de dados (.sql ou .sql.gz).');
        }

        @set_time_limit(0);
        $importer = new SqlImporter($this->backups->connectionConfig());
        $bytes = $importer->verify($paths['db']); // lança se estiver corrompido/incompleto

        $photos = null;
        $mode = null;
        if ($paths['zip']) {
            $mode = 'zip';
            $photos = $this->zipCount($paths['zip']);
        } elseif ($paths['folder_count'] > 0) {
            $mode = 'folders';
            $photos = $paths['folder_count'];
        }

        $summary = [
            'db_file' => basename($paths['db']),
            'db_bytes' => $bytes,
            'photos_mode' => $mode,
            'photos' => $photos,
            'database' => $this->backups->connectionConfig()['database'],
            'current' => $this->currentState($importer),
        ];
        $this->patch($id, ['state' => 'verified', 'summary' => $summary, 'error' => null]);

        return $summary;
    }

    /** Começa o restauro (põe-no na fila). */
    public function start(string $id, User $user): void
    {
        $this->withLock(function () use ($id, $user) {
            $status = $this->status($id);
            if ($status['source'] === 'upload' && $status['state'] !== 'verified') {
                throw new RuntimeException('Verifique os ficheiros antes de restaurar.');
            }
            if ($status['source'] !== 'upload' && $status['state'] !== 'ready') {
                throw new RuntimeException('Este restauro já foi iniciado.');
            }
            if ((int) $status['user_id'] !== (int) $user->id) {
                throw new RuntimeException('Só quem abriu esta sessão a pode iniciar.');
            }
            $this->patch($id, ['state' => 'queued', 'stage' => 'queued', 'message' => 'Na fila — vai começar dentro de momentos.']);
        });

        $this->audit($user->id, 'backup.restore_started', ['session' => $id]);
        RunRestoreJob::dispatch($id);
    }

    // ================================================================== restauro

    /** Faz o restauro (na fila). Nunca lança: o resultado fica no status.json. */
    public function run(string $id): void
    {
        @set_time_limit(0);
        $status = $this->status($id);
        if (!in_array($status['state'], ['queued', 'running'], true)) {
            return;
        }
        $this->patch($id, ['state' => 'running', 'started_at' => now()->toIso8601String()]);

        $config = $this->backups->connectionConfig();
        $importer = new SqlImporter($config);
        $paths = $this->paths($id);
        $wentDown = false;
        $wiped = false;
        $safety = null;

        try {
            // 1. Verificar outra vez (o ficheiro de uma cópia do servidor ainda não foi visto).
            $this->stage($id, 'verify', 'A verificar a cópia…');
            if (!$paths['db']) {
                throw new RuntimeException('Falta o ficheiro da base de dados.');
            }
            $bytes = $importer->verify($paths['db']);
            if (empty($status['summary'])) {
                $this->patch($id, ['summary' => [
                    'db_file' => basename($paths['db']), 'db_bytes' => $bytes,
                    'photos_mode' => $paths['zip'] ? 'zip' : null, 'photos' => $paths['zip'] ? $this->zipCount($paths['zip']) : null,
                    'database' => $config['database'], 'current' => $this->currentState($importer),
                ]]);
            }

            // 2. Guardar o estado atual — sem isto não se apaga nada.
            $this->stage($id, 'safety', 'A guardar o estado atual (para poder voltar atrás)…');
            $safety = $this->backups->directory() . '/pre-restauro-' . now()->format('Ymd-His') . '.sql.gz';
            $this->backups->dumper(false)->dump($safety);
            $this->patch($id, ['safety_file' => basename($safety)]);

            // 3. Site em manutenção (o link deste estado continua a responder).
            $this->stage($id, 'maintenance', 'A pôr o site em manutenção…');
            Artisan::call('down', ['--retry' => 60]);
            $wentDown = true;

            // 4. Apagar e importar.
            $this->stage($id, 'import', 'A apagar os dados atuais e a repor a cópia…');
            $pdo = $importer->connect();
            $wiped = true;
            $importer->wipe($pdo);
            $total = $importer->import($pdo, $paths['db'], fn (int $n) => $this->patch($id, ['progress' => $n, 'message' => "A repor a cópia… {$n} instruções"]));
            self::clearTransientTables($pdo);
            $this->patch($id, ['progress' => $total]);

            // 5. Fotos.
            $photos = null;
            if ($paths['zip']) {
                $this->stage($id, 'photos', 'A repor as fotos…');
                $photos = (new FilesRestorer())->restore($paths['zip'], $this->backups->filesRoot(), (array) config('backup.files_dirs'));
            } elseif ($paths['folder_count'] > 0) {
                $this->stage($id, 'photos', 'A repor as fotos…');
                $photos = ['restored' => $this->copyFolder($this->dir($id) . '/files', $this->backups->filesRoot()), 'skipped' => 0];
            }

            // 6. Atualizar a estrutura e arrumar.
            $this->stage($id, 'finish', 'A aplicar atualizações e a reabrir o site…');
            Artisan::call('migrate', ['--force' => true]);
            $this->registerSafetyCopy($safety, (int) $status['user_id']);
            $this->audit((int) $status['user_id'], 'backup.restored', [
                'session' => $id, 'file' => basename($paths['db']), 'statements' => $total, 'photos' => $photos['restored'] ?? 0,
                'safety_file' => basename($safety),
            ]);
            $this->afterwards();
            Artisan::call('up');
            $wentDown = false;

            $this->deleteUploads($id);
            $this->patch($id, [
                'state' => 'done', 'stage' => 'done', 'finished_at' => now()->toIso8601String(),
                'message' => 'Restauro concluído.' . ($photos ? " Fotos repostas: {$photos['restored']}." : ''),
            ]);
        } catch (Throwable $e) {
            $this->fail($id, $e, $importer, $wiped, $wentDown, $safety);
        }
    }

    /** Falhou: se já tinha apagado, repõe o estado anterior sozinho. */
    private function fail(string $id, Throwable $e, SqlImporter $importer, bool $wiped, bool $wentDown, ?string $safety): void
    {
        Log::channel('single')->error('[Restore] falhou', ['session' => $id, 'error' => $e->getMessage()]);
        $error = mb_substr($e->getMessage(), 0, 1000);

        if (!$wiped) {
            if ($safety && is_file($safety)) {
                @unlink($safety); // nada foi apagado: a cópia do estado atual não faz falta
            }
            if ($wentDown) {
                $this->safely(fn () => Artisan::call('up'));
            }
            $this->patch($id, ['state' => 'failed', 'stage' => 'failed', 'error' => $error, 'finished_at' => now()->toIso8601String(),
                'message' => 'O restauro não começou — nada foi apagado.']);

            return;
        }

        // Já tinha apagado: volta ao estado anterior.
        $this->stage($id, 'rollback', 'Algo falhou — a repor o estado anterior…');
        try {
            if (!$safety || !is_file($safety)) {
                throw new RuntimeException('Não há cópia do estado anterior.');
            }
            $pdo = $importer->connect();
            $importer->wipe($pdo);
            $importer->import($pdo, $safety);
            self::clearTransientTables($pdo);
            $this->afterwards();
            $this->safely(fn () => Artisan::call('up'));
            $this->patch($id, ['state' => 'failed', 'stage' => 'failed', 'error' => $error, 'finished_at' => now()->toIso8601String(),
                'message' => 'O restauro falhou e o estado anterior foi reposto — o site está como estava.']);
        } catch (Throwable $rollback) {
            // Último recurso: fica em manutenção e diz exatamente o que fazer.
            $this->patch($id, ['state' => 'failed', 'stage' => 'failed', 'finished_at' => now()->toIso8601String(),
                'error' => $error . ' | Reposição automática também falhou: ' . mb_substr($rollback->getMessage(), 0, 400),
                'message' => 'O site ficou em manutenção. No servidor, corra: php artisan kerugas:restore ' . ($safety ? basename($safety) : 'latest')]);
        }
    }

    /**
     * Depois de importar: os trabalhos em fila e as caches gravadas na cópia
     * são velhos — corrê-los de novo enviaria SMS/notificações antigas.
     */
    public static function clearTransientTables(PDO $pdo): void
    {
        $existing = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        foreach (['jobs', 'job_batches', 'cache', 'cache_locks'] as $table) {
            if (in_array($table, $existing, true)) {
                $pdo->exec('DELETE FROM `' . $table . '`');
            }
        }
    }

    // ================================================================== auxiliares

    /** @return array{db:?string, zip:?string, folder_count:int} */
    public function paths(string $id): array
    {
        $status = $this->status($id);
        if ($status['source'] === 'backup') {
            $run = BackupRun::find($status['backup_run_id']);

            return [
                'db' => $run ? $this->backups->path($run, 'db') : null,
                'zip' => $run && !empty($status['with_files']) ? $this->backups->path($run, 'files') : null,
                'folder_count' => 0,
            ];
        }
        $dir = $this->dir($id);
        $db = is_file($dir . '/db.sql.gz') ? $dir . '/db.sql.gz' : (is_file($dir . '/db.sql') ? $dir . '/db.sql' : null);

        return [
            'db' => $db,
            'zip' => is_file($dir . '/photos.zip') ? $dir . '/photos.zip' : null,
            'folder_count' => $this->countFiles($dir . '/files'),
        ];
    }

    public function patch(string $id, array $values): array
    {
        $status = array_merge($this->status($id), $values, ['updated_at' => now()->toIso8601String()]);
        $this->write($id, $status);

        return $status;
    }

    private function stage(string $id, string $stage, string $message): void
    {
        $this->patch($id, ['stage' => $stage, 'message' => $message]);
    }

    private function write(string $id, array $status): void
    {
        $file = $this->dir($id) . '/status.json';
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, $file);
    }

    /** Uma sessão em curso (na fila ou a correr) que não esteja parada há horas. */
    public function activeSession(): ?array
    {
        foreach (glob($this->root() . '/*/status.json') ?: [] as $file) {
            $s = json_decode((string) file_get_contents($file), true);
            if (!is_array($s) || !in_array($s['state'] ?? '', ['queued', 'running'], true)) {
                continue;
            }
            $stale = (int) config('backup.stale_after_minutes', 480) * 60;
            if (time() - (int) filemtime($file) > $stale) {
                continue; // parada (servidor reiniciou a meio)
            }

            return $s;
        }

        return null;
    }

    /** Apaga sessões antigas (mais de 24 h) que não estejam a decorrer. */
    public function cleanup(): int
    {
        $removed = 0;
        foreach (glob($this->root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $status = $dir . '/status.json';
            $s = is_file($status) ? json_decode((string) file_get_contents($status), true) : null;
            $age = time() - (int) (is_file($status) ? filemtime($status) : filemtime($dir));
            $active = is_array($s) && in_array($s['state'] ?? '', ['queued', 'running'], true);
            if (!$active && $age > 86400) {
                $this->deleteDir($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /** Uma operação de cada vez — trava por ficheiro (a cache pode viver na base de dados que vai ser apagada). */
    private function withLock(callable $fn): mixed
    {
        $h = fopen($this->root() . '/.lock', 'c');
        if (!$h || !flock($h, LOCK_EX)) {
            throw new RuntimeException('Não foi possível obter o bloqueio do restauro.');
        }
        try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function assertSpace(int $incoming): void
    {
        $free = @disk_free_space($this->root());
        $min = (int) config('backup.min_free_mb') * 1024 * 1024;
        if ($free !== false && $free - $incoming < $min) {
            throw new RuntimeException('Pouco espaço livre no servidor para receber a cópia.');
        }
    }

    private function zipCount(string $zip): int
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('A extensão "zip" do PHP não está ativa — não é possível repor as fotos a partir de um .zip.');
        }
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) {
            throw new RuntimeException('O ficheiro das fotos (.zip) está corrompido.');
        }
        $n = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            if (!str_ends_with((string) $z->getNameIndex($i), '/')) {
                $n++;
            }
        }
        $z->close();

        return $n;
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && !str_ends_with($f->getFilename(), '.part')) {
                $n++;
            }
        }

        return $n;
    }

    /** Copia as fotos enviadas (avatars/…, community/…) para a pasta real (junta; substitui as iguais). */
    private function copyFolder(string $from, string $to): int
    {
        $n = 0;
        $base = strlen(rtrim($from, '/\\')) + 1;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile() || str_ends_with($f->getFilename(), '.part')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), $base));
            $dest = rtrim($to, '/\\') . '/' . self::photoPath($rel); // valida de novo
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            if (!@copy($f->getPathname(), $dest)) {
                throw new RuntimeException('Não foi possível copiar a foto ' . $rel);
            }
            $n++;
        }

        return $n;
    }

    /** A cópia do estado anterior aparece no histórico do painel (para descarregar ou repor). */
    private function registerSafetyCopy(string $path, int $userId): void
    {
        $this->safely(fn () => BackupRun::create([
            'user_id' => \App\Models\User::whereKey($userId)->exists() ? $userId : null,
            'trigger' => 'pre_restore',
            'status' => BackupRun::COMPLETED,
            'include_files' => false,
            'db_file' => basename($path),
            'db_bytes' => (int) @filesize($path),
            'db_sha256' => hash_file('sha256', $path),
            'started_at' => now(),
            'finished_at' => now(),
        ]));
    }

    private function afterwards(): void
    {
        foreach (['cache:clear', 'queue:restart'] as $command) {
            $this->safely(fn () => Artisan::call($command));
        }
    }

    private function deleteUploads(string $id): void
    {
        $dir = $this->dir($id);
        foreach (['db.sql', 'db.sql.gz', 'photos.zip'] as $f) {
            @unlink($dir . '/' . $f);
        }
        $this->deleteDir($dir . '/files');
    }

    private function currentState(SqlImporter $importer): array
    {
        try {
            $pdo = $importer->connect();
            $tables = $pdo->query('SHOW TABLES')->rowCount();
            $users = $tables ? (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;

            return ['tables' => $tables, 'users' => $users];
        } catch (Throwable) {
            return ['tables' => null, 'users' => null];
        }
    }

    private function audit(?int $userId, string $action, array $values): void
    {
        $this->safely(fn () => AuditLog::create([
            'user_id' => $userId && \App\Models\User::whereKey($userId)->exists() ? $userId : null,
            'action' => $action,
            'entity_type' => 'backup_run',
            'entity_id' => null,
            'new_values' => $values,
            'ip_address' => request()?->ip(),
            'user_agent' => mb_substr((string) request()?->userAgent(), 0, 250),
        ]));
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::channel('single')->warning('[Restore] passo secundário falhou', ['error' => $e->getMessage()]);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
