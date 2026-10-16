<?php

namespace App\Services\Backup;

use PDO;
use RuntimeException;

/**
 * Importa um ficheiro de cópia (.sql ou .sql.gz) para MySQL/MariaDB, em PHP
 * puro (Outubro 2026). Lê em fluxo (memória constante) e separa as instruções
 * respeitando textos entre aspas, comentários e `DELIMITER` (triggers).
 *
 * Porquê em PHP e não só com o cliente `mysql`: o cliente do Alpine (MariaDB)
 * pode não conseguir autenticar num MySQL 8 (`caching_sha2_password`); o PDO
 * da própria aplicação já liga bem. Classe sem dependências do Laravel.
 */
class SqlImporter
{
    /** @param array{host?:?string,port?:int|string|null,database:string,username:string,password?:?string,unix_socket?:?string} $db */
    public function __construct(private array $db) {}

    public function connect(): PDO
    {
        $dsn = !empty($this->db['unix_socket']) && empty($this->db['host'])
            ? "mysql:unix_socket={$this->db['unix_socket']};dbname={$this->db['database']};charset=utf8mb4"
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $this->db['host'] ?: '127.0.0.1', $this->db['port'] ?: 3306, $this->db['database']);

        return new PDO($dsn, $this->db['username'], (string) ($this->db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** Cria a base de dados se ainda não existir (restauro de ensaio noutra base). */
    public function ensureDatabase(): void
    {
        $dsn = !empty($this->db['unix_socket']) && empty($this->db['host'])
            ? "mysql:unix_socket={$this->db['unix_socket']};charset=utf8mb4"
            : sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $this->db['host'] ?: '127.0.0.1', $this->db['port'] ?: 3306);
        $pdo = new PDO($dsn, $this->db['username'], (string) ($this->db['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $this->ident($this->db['database']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Confirma que o ficheiro é legível até ao fim (gzip íntegro) e que a cópia
     * terminou ("Dump completed"). Devolve os bytes (já descomprimidos).
     *
     * @throws RuntimeException
     */
    public function verify(string $file): int
    {
        $total = 0;
        $tail = '';
        $this->withWarningsAsErrors(function () use ($file, &$total, &$tail) {
            $h = $this->open($file);
            while (!$this->eof($h, $file)) {
                $chunk = $this->read($h, $file, 1 << 20);
                if ($chunk === false) {
                    throw new RuntimeException('O ficheiro está corrompido (leitura falhou).');
                }
                $total += strlen($chunk);
                $tail = substr($tail . $chunk, -400);
            }
            $this->close($h, $file);
        });

        if (!str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('O ficheiro está incompleto (a cópia não terminou — falta a marca final "Dump completed").');
        }

        return $total;
    }

    /** Apaga todas as tabelas e vistas da base de dados (ficam só os utilizadores/privilégios do MySQL). */
    public function wipe(PDO $pdo): int
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $views = $tables = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (strtoupper((string) $row[1]) === 'VIEW') {
                $views[] = (string) $row[0];
            } else {
                $tables[] = (string) $row[0];
            }
        }
        foreach ($views as $v) {
            $pdo->exec('DROP VIEW IF EXISTS ' . $this->ident($v));
        }
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->ident($t));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return count($tables) + count($views);
    }

    /**
     * Executa o ficheiro. `$progress(int $statements)` é chamado de tempos a tempos.
     *
     * @return int número de instruções executadas
     */
    public function import(PDO $pdo, string $file, ?callable $progress = null): int
    {
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('SET UNIQUE_CHECKS=0');
        $pdo->exec('SET autocommit=0'); // muito mais rápido: grava em blocos

        $count = 0;
        try {
            foreach ($this->statements($file) as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (\PDOException $e) {
                    throw new RuntimeException(sprintf(
                        'Erro na instrução %d (%s…): %s',
                        $count + 1,
                        preg_replace('/\s+/', ' ', mb_substr($sql, 0, 120)),
                        $e->getMessage(),
                    ), 0, $e);
                }
                $count++;
                if ($count % 200 === 0) {
                    $pdo->exec('COMMIT');
                }
                if ($progress && $count % 2000 === 0) {
                    $progress($count);
                }
            }
            $pdo->exec('COMMIT');
        } finally {
            try {
                $pdo->exec('SET autocommit=1');
                $pdo->exec('SET UNIQUE_CHECKS=1');
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable) {
            }
        }

        return $count;
    }

    /**
     * Separa o ficheiro em instruções completas.
     *
     * @return \Generator<int, string>
     */
    public function statements(string $file): \Generator
    {
        $h = $this->open($file);
        $delim = ';';
        $buf = '';
        $quote = null;     // ' " ` quando estamos dentro de um texto
        $block = false;    // dentro de /* ... */
        $escaped = false;  // última coisa lida foi uma barra invertida dentro de um texto

        try {
            while (($line = $this->line($h, $file)) !== false) {
                if ($quote === null && !$block && trim($buf) === '' && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                    $delim = $m[1];
                    $buf = '';
                    continue;
                }

                $i = 0;
                $n = strlen($line);
                $dl = strlen($delim);
                while ($i < $n) {
                    if ($block) {
                        $e = strpos($line, '*/', $i);
                        if ($e === false) {
                            $buf .= substr($line, $i);
                            break;
                        }
                        $buf .= substr($line, $i, $e + 2 - $i);
                        $i = $e + 2;
                        $block = false;
                        continue;
                    }

                    if ($quote !== null) {
                        if ($escaped) { // o 1.º carácter desta linha foi "escapado" na anterior
                            $buf .= $line[$i++];
                            $escaped = false;
                            continue;
                        }
                        $j = $i + strcspn($line, $quote === '`' ? '`' : $quote . '\\', $i);
                        if ($j >= $n) {
                            $buf .= substr($line, $i);
                            break;
                        }
                        if ($line[$j] === '\\') {
                            if ($j + 1 >= $n) {
                                $buf .= substr($line, $i, $j + 1 - $i);
                                $escaped = true;
                                break;
                            }
                            $buf .= substr($line, $i, $j + 2 - $i);
                            $i = $j + 2;
                            continue;
                        }
                        if (($line[$j + 1] ?? '') === $quote) { // aspas dobradas ('') = aspa literal
                            $buf .= substr($line, $i, $j + 2 - $i);
                            $i = $j + 2;
                            continue;
                        }
                        $buf .= substr($line, $i, $j + 1 - $i);
                        $i = $j + 1;
                        $quote = null;
                        continue;
                    }

                    $j = $i + strcspn($line, "'\"`/-#" . $delim[0], $i);
                    if ($j >= $n) {
                        $buf .= substr($line, $i);
                        break;
                    }
                    $buf .= substr($line, $i, $j - $i);

                    if (substr($line, $j, $dl) === $delim) {
                        $stmt = trim($buf);
                        $buf = '';
                        $i = $j + $dl;
                        if ($stmt !== '') {
                            yield $stmt;
                        }
                        continue;
                    }

                    $c = $line[$j];
                    if ($c === "'" || $c === '"' || $c === '`') {
                        $quote = $c;
                        $buf .= $c;
                        $i = $j + 1;
                    } elseif ($c === '#' || ($c === '-' && ($line[$j + 1] ?? '') === '-' && ctype_space($line[$j + 2] ?? "\n"))) {
                        $i = $n; // comentário de linha: ignora o resto
                    } elseif ($c === '/' && ($line[$j + 1] ?? '') === '*') {
                        $block = true; // comentários (incl. /*! ... */) seguem para o servidor
                        $buf .= '/*';
                        $i = $j + 2;
                    } else {
                        $buf .= $c;
                        $i = $j + 1;
                    }
                }
            }
            if (($stmt = trim($buf)) !== '') {
                yield $stmt;
            }
        } finally {
            $this->close($h, $file);
        }
    }

    // ------------------------------------------------------------------ ficheiro

    private function isGz(string $file): bool
    {
        return str_ends_with(strtolower($file), '.gz');
    }

    /** @return resource */
    private function open(string $file)
    {
        $h = $this->isGz($file) ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        if ($h === false) {
            throw new RuntimeException('Não foi possível abrir o ficheiro da cópia.');
        }

        return $h;
    }

    private function line($h, string $file): string|false
    {
        return $this->isGz($file) ? gzgets($h) : fgets($h);
    }

    private function read($h, string $file, int $len): string|false
    {
        return $this->isGz($file) ? gzread($h, $len) : fread($h, $len);
    }

    private function eof($h, string $file): bool
    {
        return $this->isGz($file) ? gzeof($h) : feof($h);
    }

    private function close($h, string $file): void
    {
        $this->isGz($file) ? gzclose($h) : fclose($h);
    }

    private function withWarningsAsErrors(callable $fn): void
    {
        set_error_handler(function (int $no, string $msg) {
            throw new RuntimeException('O ficheiro está corrompido: ' . $msg);
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
    }

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
