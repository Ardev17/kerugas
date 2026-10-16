<?php

namespace App\Services\Backup;

use RuntimeException;
use ZipArchive;

/**
 * Junta as fotos (avatares e Vizinhos) num .zip (Outubro 2026). As fotos já
 * vêm comprimidas (WebP/JPEG), por isso o zip só as arruma, sem as comprimir
 * outra vez (muito mais rápido). O ficheiro só ganha o nome final quando
 * está completo.
 */
class FilesArchiver
{
    /**
     * @param list<string> $dirs pastas (relativas a $root) a incluir
     * @return array{bytes:int, count:int}|null null quando não há ficheiros
     */
    public function archive(string $root, array $dirs, string $target): ?array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('A extensão "zip" do PHP não está ativa — as fotos não foram incluídas.');
        }

        $part = $target . '.part';
        @unlink($part);

        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro das fotos.');
        }

        $count = 0;
        foreach ($dirs as $dir) {
            $base = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                if ($zip->addFile($file->getPathname(), $relative)) {
                    $zip->setCompressionName($relative, ZipArchive::CM_STORE);
                    $count++;
                }
            }
        }

        if ($count === 0) {
            $zip->close();
            @unlink($part);

            return null;
        }
        if (!$zip->close()) {
            @unlink($part);
            throw new RuntimeException('Não foi possível terminar o ficheiro das fotos.');
        }
        if (!@rename($part, $target)) {
            @unlink($part);
            throw new RuntimeException('Não foi possível guardar o ficheiro das fotos.');
        }

        return ['bytes' => (int) filesize($target), 'count' => $count];
    }
}
