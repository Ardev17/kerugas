<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Gera o par de chaves VAPID para o Web Push no navegador (Setembro 2026):
 *   php artisan kerugas:vapid
 *
 * VAPID = curva elíptica P-256. A chave pública (65 bytes, formato não
 * comprimido) vai para o navegador; a privada (32 bytes) fica SÓ no
 * servidor e assina cada envio. Gerado com o OpenSSL do próprio PHP —
 * não precisa de nenhum pacote instalado.
 *
 * Gere UMA vez: trocar as chaves depois invalida todas as inscrições
 * existentes (as pessoas teriam de voltar a aceitar notificações).
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'kerugas:vapid';

    protected $description = 'Gera as chaves VAPID para notificações Web Push no navegador';

    public function handle(): int
    {
        [$public, $private] = self::generate();

        $this->info('Chaves VAPID geradas. Copie para o .env do servidor:');
        $this->newLine();
        $this->line("PUSH_VAPID_PUBLIC_KEY={$public}");
        $this->line("PUSH_VAPID_PRIVATE_KEY={$private}");
        $this->line('PUSH_VAPID_SUBJECT=mailto:contacto@kerugas.com');
        $this->newLine();
        $this->info('O site vai buscar a chave pública à API sozinho — não é preciso rebuild do frontend.');
        $this->info('Depois: composer require minishlink/web-push && php artisan config:cache');
        $this->newLine();
        $this->warn('A chave PRIVADA é secreta — nunca a ponha no frontend nem no git.');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} [pública, privada] em base64url */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('O OpenSSL deste PHP não conseguiu gerar uma chave P-256.');
        }

        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (!$ec || !isset($ec['x'], $ec['y'], $ec['d'])) {
            throw new RuntimeException('Não foi possível ler a chave gerada.');
        }

        $pad = fn (string $bytes) => str_pad($bytes, 32, "\0", STR_PAD_LEFT);
        $b64url = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            $b64url("\x04" . $pad($ec['x']) . $pad($ec['y'])),
            $b64url($pad($ec['d'])),
        ];
    }
}
