<?php

namespace App\Services\Community;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Fotos das mensagens dos Vizinhos KeruGás (Outubro 2026).
 *
 * Mesmas garantias da foto de perfil (AvatarService): o tipo vem do
 * CONTEÚDO, as dimensões e a memória são verificadas antes de descodificar,
 * a imagem é RE-CODIFICADA pelo GD (apaga EXIF/GPS e qualquer conteúdo
 * escondido), o nome é gerado pelo servidor (160 bits) e o ficheiro vive no
 * disco privado — só sai pela rota `community.images.show`, e só enquanto a
 * mensagem estiver visível. Diferença: mantém a proporção (lado maior 1280 px).
 */
class CommunityImageService
{
    public const DISK = 'local';

    public const DIR = 'community';

    public const MAX_KB = 5120;

    public const MIN_SIDE = 64;

    public const MAX_SIDE = 6000;

    public const MAX_PIXELS = 16_000_000;

    public const OUT_SIDE = 1280;

    public const FILE_PATTERN = '[a-f0-9]{40}\.(?:webp|jpg)';

    public static function url(?string $path): ?string
    {
        if (!is_string($path) || !preg_match('#^' . self::DIR . '/(' . self::FILE_PATTERN . ')$#', $path, $m)) {
            return null;
        }

        return url('/api/v1/community/images/' . $m[1]);
    }

    /** Valida, re-codifica e grava. Devolve o caminho relativo. */
    public function store(UploadedFile $file): string
    {
        if (!ImageGd::available()) {
            throw new HttpException(503, 'O servidor não consegue processar imagens de momento (extensão GD em falta).');
        }

        $path = $file->getRealPath();
        if (!$file->isValid() || $path === false || !is_file($path)) {
            $this->reject('Não foi possível receber a foto. Tente de novo.');
        }

        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0 || $bytes > self::MAX_KB * 1024) {
            $this->reject('A foto tem de ter no máximo 5 MB.');
        }

        $info = @getimagesize($path);
        if ($info === false) {
            $this->reject('O ficheiro não é uma imagem válida.');
        }

        [$width, $height, $type] = $info;

        if (!in_array($type, ImageGd::readableTypes(), true)) {
            $this->reject('Formato não suportado. Use uma foto JPG, PNG ou WebP.');
        }
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            $this->reject('A foto é demasiado pequena.');
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            $this->reject('A foto é demasiado grande (máximo ' . self::MAX_SIDE . ' px de lado).');
        }
        if (!ImageGd::enoughMemory($width, $height)) {
            $this->reject('A foto é demasiado grande para ser processada. Escolha uma mais pequena.');
        }

        $orientation = 1;
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }

        $encoded = $this->reencode((string) file_get_contents($path), $orientation);

        $newPath = self::DIR . '/' . bin2hex(random_bytes(20)) . '.' . ImageGd::outputExtension();
        if (!Storage::disk(self::DISK)->put($newPath, $encoded)) {
            throw new HttpException(500, 'Não foi possível guardar a foto. Tente de novo.');
        }

        return $newPath;
    }

    public function deleteFile(?string $path): void
    {
        if (!is_string($path) || !preg_match('#^' . self::DIR . '/' . self::FILE_PATTERN . '$#', $path)) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable $e) {
            Log::warning('Comunidade: não foi possível apagar a foto.', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    private function reencode(string $raw, int $orientation): string
    {
        $src = @imagecreatefromstring($raw);
        unset($raw);

        if (!$src instanceof GdImage) {
            $this->reject('Não foi possível ler a foto. Use JPG, PNG ou WebP (não animada).');
        }

        $dst = null;

        try {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, self::OUT_SIDE / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $webp = ImageGd::outputExtension() === 'webp';

            $dst = imagecreatetruecolor($nw, $nh);
            if ($webp) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                imagealphablending($dst, true);
            } else {
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            imagedestroy($src);
            $src = null;
            $dst = ImageGd::applyOrientation($dst, $orientation);

            if ($webp) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }

            ob_start();
            $ok = $webp ? imagewebp($dst, null, 80) : imagejpeg($dst, null, 82);
            $out = (string) ob_get_clean();

            if (!$ok || $out === '') {
                throw new HttpException(500, 'Não foi possível processar a foto. Tente de novo.');
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

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
