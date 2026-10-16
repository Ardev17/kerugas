<?php

namespace App\Services\Backup;

use RuntimeException;
use ZipArchive;

/**
 * Repõe as fotos de uma cópia (.zip) (Outubro 2026). Só aceita ficheiros das
 * pastas esperadas (avatars/, community/) e recusa caminhos suspeitos
 * (`..`, absolutos) — um .zip adulterado nunca escreve fora da pasta das fotos.
 */
class FilesRestorer
{
    /**
     * @param list<string> $allowedDirs
     * @return array{restored:int, skipped:int}
     */
    public function restore(string $zipPath, string $root, array $allowedDirs): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('A extensão "zip" do PHP não está ativa.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Não foi possível abrir o ficheiro das fotos (.zip).');
        }

        $dirs = array_map(fn ($d) => preg_quote($d, '#'), $allowedDirs);
        $pattern = '#^(?:' . implode('|', $dirs) . ')/[A-Za-z0-9._/-]+$#';

        $accepted = [];
        $skipped = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue; // pastas: criadas ao extrair
            }
            if (preg_match($pattern, $name) && !str_contains($name, '..') && !str_contains($name, '//')) {
                $accepted[] = $name;
            } else {
                $skipped++;
            }
        }

        $restored = 0;
        foreach (array_chunk($accepted, 500) as $chunk) {
            if (!$zip->extractTo($root, $chunk)) {
                $zip->close();
                throw new RuntimeException('Falhou a extração das fotos.');
            }
            $restored += count($chunk);
        }
        $zip->close();

        return ['restored' => $restored, 'skipped' => $skipped];
    }
}
