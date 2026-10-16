<?php

use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Comando kerugas:restore (Outubro 2026) — as proteções. Todos estes testes
 * terminam ANTES de apagar qualquer coisa: o que se prova é que, quando algo
 * não está certo, os dados atuais ficam intactos.
 */
function restoreValidDump(string $dir, string $name = 'kerugas-bd-20261009-020000-1.sql.gz'): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, gzencode("-- cópia de teste\nSELECT 1;\n-- Dump completed on 2026-10-09 02:00:00\n"));

    return $path;
}

function restoreDbName(): string
{
    return (string) config('database.connections.' . config('database.default') . '.database');
}

beforeEach(function () {
    $this->restoreDir = sys_get_temp_dir() . '/kerugas-restore-test-' . uniqid();
    mkdir($this->restoreDir, 0777, true);
    config(['backup.path' => $this->restoreDir]);
    User::factory()->count(3)->create();
});

afterEach(function () {
    File::deleteDirectory($this->restoreDir);
});

it('diz que não encontrou o ficheiro e não mexe em nada', function () {
    $this->artisan('kerugas:restore', ['file' => 'nao-existe.sql.gz'])
        ->expectsOutputToContain('Não encontrei o ficheiro')
        ->assertFailed();

    expect(User::count())->toBe(3);
});

it('recusa um ficheiro incompleto antes de apagar', function () {
    $path = $this->restoreDir . '/incompleto.sql.gz';
    file_put_contents($path, gzencode("INSERT INTO users VALUES (1);\n"));

    $this->artisan('kerugas:restore', ['file' => $path, '--confirm' => restoreDbName()])
        ->expectsOutputToContain('incompleto')
        ->expectsOutputToContain('Nada foi apagado')
        ->assertFailed();

    expect(User::count())->toBe(3);
});

it('recusa um ficheiro corrompido antes de apagar', function () {
    $good = gzencode(str_repeat("INSERT INTO a VALUES (1,'x');\n", 5000) . "-- Dump completed on x\n");
    $path = $this->restoreDir . '/corrompido.sql.gz';
    file_put_contents($path, substr($good, 0, 100) . str_repeat("\x00\xff", 200) . substr($good, 400));

    $this->artisan('kerugas:restore', ['file' => $path, '--confirm' => restoreDbName()])->assertFailed();

    expect(User::count())->toBe(3);
});

it('com a confirmação errada não apaga nada', function () {
    $path = restoreValidDump($this->restoreDir);

    $this->artisan('kerugas:restore', ['file' => $path, '--confirm' => 'outra_base'])
        ->expectsOutputToContain('Ficheiro íntegro')
        ->expectsOutputToContain('Confirmação errada')
        ->assertFailed();

    expect(User::count())->toBe(3);
});

it('sem terminal e sem --confirm não avança', function () {
    $path = restoreValidDump($this->restoreDir);

    $this->artisan('kerugas:restore', ['file' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('use --confirm=')
        ->assertFailed();

    expect(User::count())->toBe(3);
});

it('escrever o nome errado quando pergunta também não apaga', function () {
    $path = restoreValidDump($this->restoreDir);

    $this->artisan('kerugas:restore', ['file' => $path])
        ->expectsQuestion('Para continuar escreva o nome da base de dados (' . restoreDbName() . ')', 'não sei')
        ->expectsOutputToContain('Confirmação errada')
        ->assertFailed();

    expect(User::count())->toBe(3);
});

it('recusa a cópia cujo checksum não bate com o registado', function () {
    $path = restoreValidDump($this->restoreDir);
    BackupRun::create([
        'status' => BackupRun::COMPLETED, 'trigger' => 'manual', 'include_files' => false,
        'db_file' => basename($path), 'db_sha256' => str_repeat('a', 64),
    ]);

    $this->artisan('kerugas:restore', ['file' => $path, '--confirm' => restoreDbName()])
        ->expectsOutputToContain('checksum')
        ->assertFailed();

    expect(User::count())->toBe(3);
});

it('"latest" escolhe a cópia mais recente da pasta', function () {
    $old = restoreValidDump($this->restoreDir, 'kerugas-bd-20261001-020000-1.sql.gz');
    touch($old, time() - 3600);
    restoreValidDump($this->restoreDir, 'kerugas-bd-20261009-020000-2.sql.gz');

    $this->artisan('kerugas:restore', ['file' => 'latest', '--confirm' => 'errada'])
        ->expectsOutputToContain('kerugas-bd-20261009-020000-2.sql.gz')
        ->assertFailed();
});

it('encontra a cópia só pelo nome, na pasta das cópias', function () {
    restoreValidDump($this->restoreDir, 'kerugas-bd-20261009-020000-9.sql.gz');

    $this->artisan('kerugas:restore', ['file' => 'kerugas-bd-20261009-020000-9.sql.gz', '--confirm' => 'errada'])
        ->expectsOutputToContain('Ficheiro íntegro')
        ->assertFailed();
});
