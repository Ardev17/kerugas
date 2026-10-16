<?php

use App\Services\Backup\SqlImporter;

/** Importador das cópias de segurança (Outubro 2026) — separação das instruções (sem base de dados). */
function importerSample(): string
{
    return <<<'SQL'
-- comentário ; aqui
/*!40101 SET NAMES utf8mb4 */;
DROP TABLE IF EXISTS `a`;
CREATE TABLE `a` (id int);
INSERT INTO `a` VALUES (1,'x; y -- z'),(2,'it''s; ok'),(3,'back\\'),(4,'multi
line; text');
DELIMITER ;;
CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.id=1; END;;
DELIMITER ;
SELECT 1; -- fim
SQL;
}

function importerExpected(): array
{
    return [
        '/*!40101 SET NAMES utf8mb4 */',
        'DROP TABLE IF EXISTS `a`',
        'CREATE TABLE `a` (id int)',
        <<<'SQL'
INSERT INTO `a` VALUES (1,'x; y -- z'),(2,'it''s; ok'),(3,'back\\'),(4,'multi
line; text')
SQL,
        'CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.id=1; END',
        'SELECT 1',
    ];
}

function importerTmp(string $name): string
{
    return sys_get_temp_dir() . '/kerugas-importer-' . uniqid() . '-' . $name;
}

function importerFor(): SqlImporter
{
    return new SqlImporter(['database' => 'x', 'username' => 'x']);
}

it('separa as instruções sem partir textos com ; -- aspas ou quebras de linha', function () {
    $file = importerTmp('a.sql');
    file_put_contents($file, importerSample());

    expect(iterator_to_array(importerFor()->statements($file), false))->toBe(importerExpected());
    unlink($file);
});

it('lê da mesma forma um .sql.gz', function () {
    $file = importerTmp('a.sql.gz');
    file_put_contents($file, gzencode(importerSample()));

    expect(iterator_to_array(importerFor()->statements($file), false))->toBe(importerExpected());
    unlink($file);
});

it('aceita um ficheiro completo e devolve o tamanho', function () {
    $file = importerTmp('ok.sql.gz');
    file_put_contents($file, gzencode("SELECT 1;\n-- Dump completed on 2026-10-09 08:00:00\n"));

    expect(importerFor()->verify($file))->toBeGreaterThan(10);
    unlink($file);
});

it('recusa um ficheiro incompleto (sem a marca final)', function () {
    $file = importerTmp('incompleto.sql.gz');
    file_put_contents($file, gzencode("SELECT 1;\nINSERT INTO a VALUES (1)"));

    expect(fn () => importerFor()->verify($file))->toThrow(RuntimeException::class, 'incompleto');
    unlink($file);
});

it('recusa um ficheiro .gz truncado ou corrompido', function () {
    $good = gzencode(str_repeat("INSERT INTO a VALUES (1,'texto de teste');\n", 5000) . "-- Dump completed on x\n");

    $truncated = importerTmp('truncado.sql.gz');
    file_put_contents($truncated, substr($good, 0, intdiv(strlen($good), 2)));
    expect(fn () => importerFor()->verify($truncated))->toThrow(RuntimeException::class);

    $corrupt = importerTmp('corrompido.sql.gz');
    file_put_contents($corrupt, substr($good, 0, 100) . str_repeat("\x00\xff", 200) . substr($good, 400));
    expect(fn () => importerFor()->verify($corrupt))->toThrow(RuntimeException::class);

    unlink($truncated);
    unlink($corrupt);
});
