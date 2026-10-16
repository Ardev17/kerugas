<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Push para iPhone, direto na Apple (APNs, autenticação por chave .p8) —
 * Outubro 2026.
 *
 * Porque não passa pelo Firebase: o app iOS entrega ao servidor o token
 * nativo da Apple (é o que o @capacitor/push-notifications devolve no
 * iPhone sem o SDK do Firebase). Assim o app iOS não precisa de
 * GoogleService-Info.plist nem de nenhum pacote extra — só da chave .p8
 * criada no portal da Apple. Android continua no FcmPushSender.
 *
 * Tal como o FcmPushSender, usa o cliente Http do Laravel (HTTP/2 é
 * obrigatório na Apple) e não instala dependências novas. O JWT de
 * autenticação vale até 60 minutos e a Apple recusa renovações em menos
 * de 20: fica em cache 40 minutos.
 */
class ApnsPushSender
{
    private const PRODUCTION = 'https://api.push.apple.com';
    private const SANDBOX = 'https://api.sandbox.push.apple.com';

    /** Razões em que o token nunca mais vai funcionar (app desinstalado, token errado). */
    private const DEAD_TOKEN_REASONS = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    public function isEnabled(): bool
    {
        $cfg = $this->config();

        return $cfg['key_path'] !== '' && is_readable($cfg['key_path'])
            && $cfg['key_id'] !== '' && $cfg['team_id'] !== '' && $cfg['bundle_id'] !== '';
    }

    public function send(string $deviceToken, PushMessage $message): PushResult
    {
        if (!$this->isEnabled()) {
            return PushResult::FAILED;
        }

        // O token do APNs é hexadecimal. Qualquer outra coisa (um token do
        // Firebase de um registo antigo, por exemplo) nem chega à rede.
        if (preg_match('/^[0-9a-fA-F]{32,200}$/', $deviceToken) !== 1) {
            return PushResult::INVALID_TOKEN;
        }

        // O plugin do Capacitor entrega o token em hexadecimal maiúsculo.
        $deviceToken = strtolower($deviceToken);

        $cfg = $this->config();
        $host = $cfg['sandbox'] ? self::SANDBOX : self::PRODUCTION;

        try {
            $response = Http::withOptions(['version' => 2.0])
                ->withHeaders([
                    'authorization' => 'bearer ' . $this->jwt(),
                    'apns-topic' => $cfg['bundle_id'],
                    'apns-push-type' => 'alert',
                    // 10 = entrega imediata (pedidos não podem esperar).
                    'apns-priority' => $message->channel === PushMessage::CHANNEL_ORDERS ? '10' : '5',
                    'apns-expiration' => (string) (time() + 6 * 3600),
                ])
                ->timeout(10)
                ->post("{$host}/3/device/{$deviceToken}", $this->payload($message));
        } catch (Throwable $e) {
            Log::channel('single')->warning('[ApnsPushSender] Falha de rede ou de autenticação com a Apple', ['error' => $e->getMessage()]);

            return PushResult::FAILED;
        }

        if ($response->successful()) {
            return PushResult::SENT;
        }

        $reason = (string) $response->json('reason');

        if ($response->status() === 410 || in_array($reason, self::DEAD_TOKEN_REASONS, true)) {
            return PushResult::INVALID_TOKEN;
        }

        // Token JWT recusado/expirado: limpar a cache para o próximo envio gerar outro.
        if (in_array($reason, ['ExpiredProviderToken', 'InvalidProviderToken'], true) || $response->status() === 403) {
            Cache::forget($this->cacheKey());
        }

        Log::channel('single')->warning('[ApnsPushSender] A Apple recusou a mensagem', [
            'status' => $response->status(),
            'reason' => $reason,
        ]);

        return PushResult::FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(PushMessage $message): array
    {
        return array_merge(
            // `data` do FCM são todas strings; aqui vão na raiz do JSON e o
            // app lê-as em notification.data (iguais às do Android).
            array_map('strval', $message->data),
            [
                'path' => $message->path,
                'aps' => [
                    'alert' => ['title' => $message->title, 'body' => $message->body],
                    'sound' => 'default',
                    // Agrupa as notificações por tipo (pedidos, alertas…) no ecrã de bloqueio.
                    'thread-id' => $message->channel,
                ],
            ]
        );
    }

    /**
     * @return array{key_path:string, key_id:string, team_id:string, bundle_id:string, sandbox:bool}
     */
    private function config(): array
    {
        $cfg = (array) config('kerugas.push.apns', []);

        return [
            'key_path' => (string) ($cfg['key_path'] ?? ''),
            'key_id' => (string) ($cfg['key_id'] ?? ''),
            'team_id' => (string) ($cfg['team_id'] ?? ''),
            'bundle_id' => (string) ($cfg['bundle_id'] ?? 'ao.kerugas.app'),
            'sandbox' => filter_var($cfg['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function cacheKey(): string
    {
        return 'apns-jwt:' . md5(implode('|', $this->config()));
    }

    private function jwt(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(40), function () {
            $cfg = $this->config();

            $segments = [
                self::base64Url((string) json_encode(['alg' => 'ES256', 'kid' => $cfg['key_id']])),
                self::base64Url((string) json_encode(['iss' => $cfg['team_id'], 'iat' => time()])),
            ];
            $input = implode('.', $segments);

            $key = openssl_pkey_get_private((string) file_get_contents($cfg['key_path']));
            if ($key === false) {
                throw new RuntimeException('APNs: a chave .p8 não é válida.');
            }
            if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('APNs: não foi possível assinar o token.');
            }

            return $input . '.' . self::base64Url(self::derToRaw($der));
        });
    }

    /** Assinatura ECDSA: o OpenSSL devolve DER, o JWT (ES256) quer R||S com 32 bytes cada. */
    private static function derToRaw(string $der): string
    {
        // 30 <len> 02 <lenR> R 02 <lenS> S
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($int, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0] . $parts[1];
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
