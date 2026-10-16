<?php

namespace App\Services\Media;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Foto de perfil (Outubro 2026) — processamento seguro do upload.
 *
 * Medidas, por ordem:
 *  1. O ficheiro nunca é guardado como chegou. O tipo é lido do CONTEÚDO
 *     (getimagesize), nunca da extensão nem do Content-Type do cliente.
 *     Só JPEG, PNG e WebP (sem SVG, GIF, HEIC, etc.).
 *  2. Tamanho máx. 5 MB e dimensões verificadas ANTES de descodificar
 *     (proteção contra "bombas de descompressão": um PNG de poucos KB pode
 *     declarar 50 000 × 50 000 px e esgotar a memória).
 *  3. A imagem é descodificada e RE-CODIFICADA pelo GD num quadrado de
 *     512 × 512 px (WebP; JPEG se o GD não tiver WebP). Isto descarta os
 *     metadados (EXIF, GPS, nome do telemóvel) e qualquer conteúdo
 *     escondido no ficheiro original (ficheiros "poliglota" imagem+PHP/HTML).
 *  4. Nome aleatório gerado pelo servidor (160 bits) — nunca o do cliente;
 *     sem como adivinhar nem como escolher o caminho (sem path traversal).
 *  5. Guardado no disco PRIVADO (`storage/app/private/avatars`), fora da
 *     pasta pública: o servidor web nunca executa nem serve diretamente o
 *     ficheiro. Só sai pela rota GET /api/v1/avatars/{ficheiro}
 *     (AvatarController), com nome validado por regex, Content-Type fixo e
 *     cabeçalhos `nosniff`/CSP.
 *  6. A foto antiga é apagada depois de a nova ficar gravada. A troca lê e
 *     grava `avatar_path` com a linha bloqueada (lockForUpdate), por isso
 *     dois envios em simultâneo (ou envio + remoção pela moderação) nunca
 *     deixam ficheiros esquecidos no disco; o comando `kerugas:avatars-prune`
 *     (diário) apaga qualquer ficheiro que mesmo assim fique sem dono.
 *  7. Antes de descodificar, confirma que a memória do PHP chega — uma foto
 *     grande dá um erro claro (422), nunca um "500" a meio.
 */
class AvatarService
{
    public const DISK = 'local';

    public const DIR = 'avatars';

    /** Lado do quadrado final, em px. */
    public const SIZE = 512;

    public const MAX_KB = 5120;

    public const MIN_SIDE = 64;

    public const MAX_SIDE = 6000;

    /** ~16 MP (as câmaras comuns de telemóvel): ~64 MB de RAM no GD. */
    public const MAX_PIXELS = 16_000_000;

    /** O único formato de nome que o servidor aceita servir. */
    public const FILE_PATTERN = '[a-f0-9]{40}\.(?:webp|jpg)';

    public static function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }

    /** URL público da foto (ou null). Funciona sem sessão iniciada. */
    public static function url(?string $path): ?string
    {
        if (!is_string($path) || !preg_match('#^' . self::DIR . '/(' . self::FILE_PATTERN . ')$#', $path, $m)) {
            return null;
        }

        return url('/api/v1/avatars/' . $m[1]);
    }

    /**
     * Valida, re-codifica e grava a nova foto; apaga a anterior.
     *
     * @throws ValidationException se o ficheiro não for uma imagem aceitável
     */
    public function store(User $user, UploadedFile $file): User
    {
        if (!self::gdAvailable()) {
            throw new HttpException(503, 'O servidor não consegue processar imagens de momento (extensão GD em falta).');
        }

        $path = $file->getRealPath();
        if (!$file->isValid() || $path === false || !is_file($path)) {
            $this->reject('Não foi possível receber a imagem. Tente de novo.');
        }

        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0 || $bytes > self::MAX_KB * 1024) {
            $this->reject('A imagem tem de ter no máximo 5 MB.');
        }

        $info = @getimagesize($path);
        if ($info === false) {
            $this->reject('O ficheiro não é uma imagem válida.');
        }

        [$width, $height, $type] = $info;

        if (!in_array($type, $this->readableTypes(), true)) {
            $this->reject('Formato não suportado. Use uma foto JPG, PNG ou WebP.');
        }

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            $this->reject('A imagem é demasiado pequena (mínimo ' . self::MIN_SIDE . ' × ' . self::MIN_SIDE . ' px).');
        }

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            $this->reject('A imagem é demasiado grande (máximo ' . self::MAX_SIDE . ' px de lado).');
        }

        $this->assertEnoughMemory($width, $height);

        $orientation = 1;
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }

        $encoded = $this->reencode((string) file_get_contents($path), $orientation);

        $extension = $this->outputExtension();
        $newPath = self::DIR . '/' . bin2hex(random_bytes(20)) . '.' . $extension;
        $disk = Storage::disk(self::DISK);

        if (!$disk->put($newPath, $encoded)) {
            throw new HttpException(500, 'Não foi possível guardar a imagem. Tente de novo.');
        }

        try {
            $oldPath = $this->swap($user, $newPath);
        } catch (Throwable $e) {
            $disk->delete($newPath);
            throw $e;
        }

        $this->deleteFile($oldPath);

        return $user;
    }

    /**
     * Remove a foto (ficheiro + referência). Devolve o caminho removido, ou
     * null se a conta já não tinha foto.
     */
    public function remove(User $user): ?string
    {
        $oldPath = $this->swap($user, null);
        $this->deleteFile($oldPath);

        return $oldPath;
    }

    /**
     * Troca `avatar_path` com a linha bloqueada e devolve o valor que estava
     * MESMO na base de dados (não o do modelo carregado no início do pedido).
     */
    private function swap(User $user, ?string $newPath): ?string
    {
        return DB::transaction(function () use ($user, $newPath) {
            $current = User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->value('avatar_path');

            if ($current !== $newPath) {
                $user->forceFill(['avatar_path' => $newPath])->save();
            }

            return $current;
        });
    }

    /** O GD guarda ~4–5 bytes por píxel; recusa antes de rebentar o memory_limit. */
    private function assertEnoughMemory(int $width, int $height): void
    {
        $limit = self::iniBytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return; // sem limite
        }

        $needed = $width * $height * 5 + self::SIZE * self::SIZE * 5 + 8 * 1024 * 1024;

        if (memory_get_usage(true) + $needed > $limit) {
            $this->reject('A imagem é demasiado grande para ser processada. Escolha uma foto mais pequena.');
        }
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Apaga o ficheiro, só se o caminho tiver o formato gerado por nós. */
    public function deleteFile(?string $path): void
    {
        if (!is_string($path) || !preg_match('#^' . self::DIR . '/' . self::FILE_PATTERN . '$#', $path)) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable $e) {
            Log::warning('Avatar: não foi possível apagar o ficheiro antigo.', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /** @return list<int> */
    private function readableTypes(): array
    {
        $types = [];
        $gd = imagetypes();

        if ($gd & IMG_JPG) {
            $types[] = IMAGETYPE_JPEG;
        }
        if ($gd & IMG_PNG) {
            $types[] = IMAGETYPE_PNG;
        }
        if (($gd & IMG_WEBP) && function_exists('imagecreatefromwebp')) {
            $types[] = IMAGETYPE_WEBP;
        }

        return $types;
    }

    private function outputExtension(): string
    {
        return (imagetypes() & IMG_WEBP) && function_exists('imagewebp') ? 'webp' : 'jpg';
    }

    private function reencode(string $raw, int $orientation): string
    {
        $src = @imagecreatefromstring($raw);
        unset($raw);

        if (!$src instanceof GdImage) {
            // Inclui WebP/PNG animados e ficheiros corrompidos/truncados.
            $this->reject('Não foi possível ler a imagem. Use uma foto JPG, PNG ou WebP (não animada).');
        }

        $dst = null;

        try {
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $sx = intdiv($w - $side, 2);
            $sy = intdiv($h - $side, 2);

            $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
            $webp = $this->outputExtension() === 'webp';

            if ($webp) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                imagealphablending($dst, true);
            } else {
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            }

            // O enquadramento escolhido pela pessoa já vem do navegador
            // (o ecrã de recorte envia um quadrado); aqui só se garante o
            // quadrado ao centro para clientes que enviem outra coisa.
            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);

            // A orientação aplica-se já ao quadrado de 512 px (o recorte central
            // não muda com rotações de 90°/180° nem espelhos) — rodar a foto
            // original duplicaria a memória usada.
            imagedestroy($src);
            $src = null;
            $dst = $this->applyOrientation($dst, $orientation);

            if ($webp) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }

            ob_start();
            $ok = $webp ? imagewebp($dst, null, 82) : imagejpeg($dst, null, 85);
            $out = (string) ob_get_clean();

            if (!$ok || $out === '') {
                throw new HttpException(500, 'Não foi possível processar a imagem. Tente de novo.');
            }

            return $out;
        } finally {
            if ($src instanceof GdImage) {
                imagedestroy($src);
            }
            if ($dst instanceof GdImage) {
                imagedestroy($dst);
            }
        }
    }

    /** Fotos de telemóvel trazem a rotação no EXIF; o GD ignora-a. */
    private function applyOrientation(GdImage $img, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated instanceof GdImage) {
                imagedestroy($img);
                $img = $rotated;
            }
        }

        match ($orientation) {
            2, 5, 7 => imageflip($img, IMG_FLIP_HORIZONTAL),
            4 => imageflip($img, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $img;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['avatar' => $message]);
    }
}
