<?php

namespace App\Services\Community;

use GdImage;

/** Auxiliares de GD partilhados pelo processamento das fotos da comunidade. */
class ImageGd
{
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }

    /** @return list<int> */
    public static function readableTypes(): array
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

    public static function outputExtension(): string
    {
        return (imagetypes() & IMG_WEBP) && function_exists('imagewebp') ? 'webp' : 'jpg';
    }

    public static function enoughMemory(int $width, int $height): bool
    {
        $limit = self::iniBytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }

        $needed = $width * $height * 5 + 1280 * 1280 * 5 + 8 * 1024 * 1024;

        return memory_get_usage(true) + $needed <= $limit;
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

    public static function applyOrientation(GdImage $img, int $orientation): GdImage
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
}
