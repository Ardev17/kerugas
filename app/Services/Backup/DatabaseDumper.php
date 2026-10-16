<?php

namespace App\Services\Backup;

use PDO;
use RuntimeException;

/**
 * Cópia da base de dados MySQL/MariaDB para um ficheiro `.sql.gz` (Outubro 2026).
 *
 * 1.º tenta o `mysqldump` (rápido, o padrão da indústria); se não existir no
 * servidor — ou falhar — usa um exportador próprio em PHP, para o botão de
 * backup funcionar em qualquer sítio (Laragon, Docker, servidor). Nos dois
 * casos a leitura é consistente (um "instantâneo" único, sem parar o site)
 * e o ficheiro só aparece com o nome final quando está completo.
 *
 * Classe sem dependências do Laravel: recebe a configuração no construtor.
 */
class DatabaseDumper
{
    /** @param array{host?:?string,port?:int|string|null,database:string,username:string,password?:?string,unix_socket?:?string} $db */
    public function __construct(private array $db, private string $binary = 'mysqldump') {}

    /** @return array{bytes:int, sha256:string, method:string, note:?string} */
    public function dump(string $target): array
    {
        $part = $target . '.part';
        @unlink($part);
        $note = null;

        try {
            $binary = $this->findBinary();
            if ($binary !== null) {
                try {
                    $this->viaMysqldump($binary, $part);
                    $method = 'mysqldump';
                } catch (RuntimeException $e) {
                    // O mysqldump existe mas falhou (ex.: cliente incompatível
                    // com o servidor): o exportador próprio tenta a seguir.
                    @unlink($part);
                    $note = 'O mysqldump falhou (' . mb_substr($e->getMessage(), 0, 300) . '); foi usado o exportador interno.';
                    $this->viaPhp($part);
                    $method = 'php';
                }
            } else {
                $this->viaPhp($part);
                $method = 'php';
            }

            if (!@rename($part, $target)) {
                throw new RuntimeException('Não foi possível guardar o ficheiro da cópia.');
            }
        } catch (\Throwable $e) {
            @unlink($part);
            throw $e;
        }

        return [
            'bytes' => (int) filesize($target),
            'sha256' => (string) hash_file('sha256', $target),
            'method' => $method,
            'note' => $note,
        ];
    }

    // ------------------------------------------------------------------ mysqldump

    private function findBinary(): ?string
    {
        $candidates = [$this->binary];
        if ($this->binary === 'mysqldump') {
            $candidates[] = 'mariadb-dump';
        }
        foreach ($candidates as $name) {
            if (str_contains($name, '/') || str_contains($name, '\\')) {
                if (is_file($name)) {
                    return $name;
                }
                continue;
            }
            $dirs = explode(PATH_SEPARATOR, (string) getenv('PATH'));
            $exts = DIRECTORY_SEPARATOR === '\\' ? ['.exe', '.cmd', '.bat', ''] : [''];
            foreach ($dirs as $dir) {
                foreach ($exts as $ext) {
                    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                    if ($dir !== '' && is_file($path)) {
                        return $path;
                    }
                }
            }
        }

        return null;
    }

    private function viaMysqldump(string $binary, string $part): void
    {
        $isMaria = stripos($this->run([$binary, '--version'])['out'], 'mariadb') !== false;

        $cmd = [$binary, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--default-character-set=utf8mb4'];
        if (!$isMaria) {
            $cmd[] = '--no-tablespaces'; // MySQL 8: sem o privilégio PROCESS o dump falharia
        }
        if (!empty($this->db['unix_socket']) && empty($this->db['host'])) {
            $cmd[] = '--socket=' . $this->db['unix_socket'];
        } else {
            $cmd[] = '--host=' . ($this->db['host'] ?: '127.0.0.1');
            if (!empty($this->db['port'])) {
                $cmd[] = '--port=' . $this->db['port'];
            }
            $cmd[] = '--protocol=tcp';
        }
        $cmd[] = '--user=' . $this->db['username'];
        $cmd[] = $this->db['database'];

        $errFile = $part . '.err';
        $env = array_merge(getenv() ?: [], ['MYSQL_PWD' => (string) ($this->db['password'] ?? '')]);
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Não foi possível arrancar o mysqldump.');
        }

        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            proc_terminate($proc);
            throw new RuntimeException('Não foi possível criar o ficheiro da cópia.');
        }
        $tail = '';
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1 << 20);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            gzwrite($gz, $chunk);
            $tail = substr($tail . $chunk, -300);
        }
        fclose($pipes[1]);
        gzclose($gz);
        $code = proc_close($proc);
        $err = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';
        @unlink($errFile);

        if ($code !== 0) {
            throw new RuntimeException($err !== '' ? $err : "mysqldump terminou com o código {$code}.");
        }
        if (!str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('A cópia do mysqldump terminou incompleta.' . ($err !== '' ? " {$err}" : ''));
        }
    }

    /** @return array{out:string, code:int} */
    private function run(array $cmd): array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['out' => '', 'code' => -1];
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['out' => $out, 'code' => proc_close($proc)];
    }

    // ------------------------------------------------------------------ PHP puro

    private function viaPhp(string $part): void
    {
        $dsn = !empty($this->db['unix_socket']) && empty($this->db['host'])
            ? "mysql:unix_socket={$this->db['unix_socket']};dbname={$this->db['database']};charset=utf8mb4"
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $this->db['host'] ?: '127.0.0.1', $this->db['port'] ?: 3306, $this->db['database']);

        $pdo = new PDO($dsn, $this->db['username'], (string) ($this->db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // linhas em fluxo: memória constante
        ]);
        // Instantâneo único: o que for escrito durante a cópia não a mistura.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Não foi possível criar o ficheiro da cópia.');
        }

        try {
            gzwrite($gz, "-- KeruGás — cópia da base de dados `{$this->db['database']}` (exportador interno)\n");
            gzwrite($gz, "/*!40101 SET NAMES utf8mb4 */;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = [];
            $views = [];
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
                if (strtoupper((string) $row[1]) === 'VIEW') {
                    $views[] = (string) $row[0];
                } else {
                    $tables[] = (string) $row[0];
                }
            }

            foreach ($tables as $table) {
                $quoted = $this->ident($table);
                $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
                gzwrite($gz, "DROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n");
                $this->dumpRows($pdo, $gz, $table);
            }
            foreach ($views as $view) {
                $quoted = $this->ident($view);
                $create = $pdo->query("SHOW CREATE VIEW {$quoted}")->fetch(PDO::FETCH_NUM);
                gzwrite($gz, "DROP VIEW IF EXISTS {$quoted};\n{$create[1]};\n\n");
            }

            // Triggers (precisam das tabelas já criadas). Procedimentos e eventos não
            // são exportados por este exportador — o mysqldump, que é o 1.º método, sim.
            foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
                $quoted = $this->ident((string) $trigger['Trigger']);
                $create = $pdo->query("SHOW CREATE TRIGGER {$quoted}")->fetch(PDO::FETCH_NUM);
                gzwrite($gz, "DELIMITER ;;\nDROP TRIGGER IF EXISTS {$quoted};;\n{$create[2]};;\nDELIMITER ;\n\n");
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- Dump completed on " . date('Y-m-d H:i:s') . "\n");
        } finally {
            gzclose($gz);
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
        }
    }

    /** @param resource $gz */
    private function dumpRows(PDO $pdo, $gz, string $table): void
    {
        $quoted = $this->ident($table);

        // Colunas binárias vão em hexadecimal (0x...) para não se estragarem.
        $binary = [];
        foreach ($pdo->query("SHOW COLUMNS FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC) as $i => $col) {
            $binary[$i] = (bool) preg_match('/blob|binary|bit|geometry|point|polygon|linestring/i', (string) $col['Type']);
        }

        $stmt = $pdo->query("SELECT * FROM {$quoted}");
        $batch = [];
        $size = 0;
        $flush = function () use (&$batch, &$size, $gz, $quoted): void {
            if ($batch) {
                gzwrite($gz, "INSERT INTO {$quoted} VALUES " . implode(",\n", $batch) . ";\n");
                $batch = [];
                $size = 0;
            }
        };

        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $values = [];
            foreach ($row as $i => $v) {
                if ($v === null) {
                    $values[] = 'NULL';
                } elseif (($binary[$i] ?? false) && $v !== '') {
                    $values[] = '0x' . bin2hex((string) $v);
                } else {
                    $values[] = $pdo->quote((string) $v);
                }
            }
            $line = '(' . implode(',', $values) . ')';
            $batch[] = $line;
            $size += strlen($line);
            if (count($batch) >= 500 || $size >= 1_000_000) {
                $flush();
            }
        }
        $flush();
        $stmt->closeCursor();
        gzwrite($gz, "\n");
    }

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
