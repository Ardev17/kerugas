<?php

namespace App\Support;

/**
 * Versões do app nativo (Outubro 2026). Só formatos "1", "1.2", "1.2.3" ou
 * "1.2.3.4" são aceites — qualquer outra coisa é tratada como "desconhecida"
 * (nunca bloqueia ninguém por um cabeçalho estranho).
 */
final class AppVersion
{
    private const PATTERN = '/^\d{1,4}(\.\d{1,4}){0,3}$/';

    public static function valid(?string $version): bool
    {
        return is_string($version) && preg_match(self::PATTERN, $version) === 1;
    }

    /**
     * @return array{min_version:string, latest_version:string, store_url:string, published:bool,
     *               apk_url:?string, apk_version:?string, update_url:string}
     */
    public static function android(): array
    {
        $cfg = (array) config('kerugas.app.android', []);
        $min = (string) ($cfg['min_version'] ?? '1.0.0');
        $latest = (string) ($cfg['latest_version'] ?? $min);

        $min = self::valid($min) ? $min : '1.0.0';
        $latest = self::valid($latest) && version_compare($latest, $min, '>=') ? $latest : $min;
        $store = (string) ($cfg['store_url'] ?? '');
        $storeUrl = str_starts_with($store, 'https://') ? $store : 'https://play.google.com/store/apps/details?id=ao.kerugas.app';
        $published = filter_var($cfg['published'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Versão de teste (Outubro 2026): só https:// e só um rótulo curto e simples.
        $apk = trim((string) ($cfg['apk_url'] ?? ''));
        $apkUrl = str_starts_with($apk, 'https://') && filter_var($apk, FILTER_VALIDATE_URL) ? $apk : null;
        $apkLabel = trim((string) ($cfg['apk_version'] ?? ''));
        $apkVersion = $apkUrl && preg_match('/^[0-9A-Za-z.\-+ ]{1,20}$/', $apkLabel) === 1 ? $apkLabel : null;

        return [
            'min_version' => $min,
            'latest_version' => $latest,
            'store_url' => $storeUrl,
            // Só é `true` quando o app já está na Play Store: o site usa isto
            // para mostrar (ou esconder) o botão "Baixar app".
            'published' => $published,
            'apk_url' => $apkUrl,
            'apk_version' => $apkVersion,
            // Para onde o aviso "Atualize o app" leva: a Play Store quando o
            // app lá está; antes disso, o APK de teste (se houver).
            'update_url' => $published ? $storeUrl : ($apkUrl ?? $storeUrl),
        ];
    }

    /**
     * App da App Store (Outubro 2026). Só conta como publicado com um link
     * https://apps.apple.com/… — nunca se mostra um botão para um link vazio.
     * As versões seguem a mesma regra do Android (inválido = 1.0.0).
     *
     * @return array{min_version:string, latest_version:string, published:bool, store_url:?string}
     */
    public static function ios(): array
    {
        $cfg = (array) config('kerugas.app.ios', []);
        $url = trim((string) ($cfg['store_url'] ?? ''));
        $valid = str_starts_with($url, 'https://apps.apple.com/');
        $published = $valid && filter_var($cfg['published'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $min = (string) ($cfg['min_version'] ?? '1.0.0');
        $latest = (string) ($cfg['latest_version'] ?? $min);
        $min = self::valid($min) ? $min : '1.0.0';
        $latest = self::valid($latest) && version_compare($latest, $min, '>=') ? $latest : $min;

        return [
            'min_version' => $min,
            'latest_version' => $latest,
            'published' => $published,
            'store_url' => $valid ? $url : null,
        ];
    }

    /** `$platform` = valor do cabeçalho X-App-Platform ("android" ou "ios"). */
    public static function belowMinimum(?string $version, string $platform = 'android'): bool
    {
        $min = $platform === 'ios' ? self::ios()['min_version'] : self::android()['min_version'];

        return self::valid($version) && version_compare((string) $version, $min, '<');
    }
}
