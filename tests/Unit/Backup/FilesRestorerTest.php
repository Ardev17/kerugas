<?php

use App\Services\Backup\FilesRestorer;
use Illuminate\Support\Facades\File;

/** Restauro das fotos (Outubro 2026) — só pastas esperadas, nunca fora da pasta. */
it('repõe só as fotos das pastas esperadas e ignora caminhos suspeitos', function () {
    $base = sys_get_temp_dir() . '/kerugas-restorer-' . uniqid();
    mkdir($base . '/root', 0777, true);

    $zipPath = $base . '/fotos.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('avatars/a.webp', 'foto-a');
    $zip->addFromString('community/b.webp', 'foto-b');
    $zip->addFromString('../fora.txt', 'malicioso');
    $zip->addFromString('/absoluto.txt', 'malicioso');
    $zip->addFromString('avatars/../../fora2.txt', 'malicioso');
    $zip->addFromString('outra/x.txt', 'não é uma pasta de fotos');
    $zip->close();

    $result = (new FilesRestorer())->restore($zipPath, $base . '/root', ['avatars', 'community']);

    expect($result)->toBe(['restored' => 2, 'skipped' => 4]);
    expect(file_get_contents($base . '/root/avatars/a.webp'))->toBe('foto-a');
    expect(file_get_contents($base . '/root/community/b.webp'))->toBe('foto-b');
    expect(file_exists($base . '/fora.txt'))->toBeFalse();
    expect(file_exists($base . '/fora2.txt'))->toBeFalse();
    expect(file_exists($base . '/root/outra/x.txt'))->toBeFalse();

    File::deleteDirectory($base);
});

it('recusa um .zip que não abre', function () {
    $file = sys_get_temp_dir() . '/kerugas-nao-zip-' . uniqid() . '.zip';
    file_put_contents($file, 'isto não é um zip');

    expect(fn () => (new FilesRestorer())->restore($file, sys_get_temp_dir(), ['avatars']))->toThrow(RuntimeException::class);
    unlink($file);
});
