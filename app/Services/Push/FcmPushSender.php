<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging — API HTTP v1 (a API "legacy" com server key
 * foi desligada pela Google em 2024; esta é a única suportada).
 *
 * Implementado diretamente com o cliente Http do Laravel em vez de um
 * pacote de terceiros: são dois pedidos (trocar a conta de serviço por um
 * access token OAuth2, e enviar a mensagem), e assim não há dependência
 * nova para instalar/auditar no servidor.
 *
 * O access token vale 1 hora; fica em cache 55 minutos, por isso um pico
 * de notificações (ex: posto volta a ter gás e 300 alertas disparam) faz
 * UMA troca de token, não 300.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array{project_id: string, client_email: string, private_key: string, token_uri?: string} */
    private array $credentials;

    public function __construct(string $credentialsPath)
    {
        if (!is_readable($credentialsPath)) {
            throw new RuntimeException("FCM: ficheiro de credenciais não encontrado/legível em {$credentialsPath}");
        }

        $decoded = json_decode((string) file_get_contents($credentialsPath), true);

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (!is_array($decoded) || empty($decoded[$key])) {
                throw new RuntimeException("FCM: o ficheiro de credenciais não tem '{$key}' — é mesmo o JSON de uma conta de serviço do Firebase?");
            }
        }

        $this->credentials = $decoded;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function send(string $deviceToken, PushMessage $message): PushResult
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(10)
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->credentials['project_id']}/messages:send",
                    ['message' => $this->buildMessage($deviceToken, $message)]
                );
        } catch (Throwable $e) {
            Log::channel('single')->warning('[FcmPushSender] Falha ao enviar push (rede ou autenticação OAuth2 da conta de serviço)', ['error' => $e->getMessage()]);

            return PushResult::FAILED;
        }

        if ($response->successful()) {
            return PushResult::SENT;
        }

        if ($this->isInvalidTokenResponse($response->status(), (array) $response->json())) {
            return PushResult::INVALID_TOKEN;
        }

        // 401: o access token em cache deixou de valer (ex: chave da conta
        // de serviço revogada/rodada). Limpar para a próxima tentativa
        // pedir um novo, em vez de falhar durante 55 minutos.
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
        }

        Log::channel('single')->warning('[FcmPushSender] FCM recusou a mensagem', [
            'status' => $response->status(),
            'body' => $response->json('error.message'),
        ]);

        return PushResult::FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessage(string $deviceToken, PushMessage $message): array
    {
        // FCM exige que todos os valores de `data` sejam strings.
        $data = array_map('strval', array_merge($message->data, ['path' => $message->path]));

        return [
            'token' => $deviceToken,
            'notification' => [
                'title' => $message->title,
                'body' => $message->body,
            ],
            'data' => $data,
            'android' => [
                // HIGH: entrega imediata mesmo com o telemóvel em modo de
                // poupança (Doze) — um condutor não pode receber o pedido
                // 15 minutos depois.
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => $message->channel,
                    'icon' => 'ic_stat_kerugas',
                    'color' => '#0B5CFF',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isInvalidTokenResponse(int $status, array $body): bool
    {
        // 404 / UNREGISTERED: o app foi desinstalado ou os dados limpos.
        if ($status === 404) {
            return true;
        }

        $errorCodes = array_map(
            fn ($detail) => data_get($detail, 'errorCode'),
            (array) data_get($body, 'error.details', [])
        );

        if (in_array('UNREGISTERED', $errorCodes, true)) {
            return true;
        }

        // 400 INVALID_ARGUMENT só conta como token inválido quando é
        // mesmo o token que está mal — o mesmo código também aparece para
        // uma mensagem mal formada, e aí apagar o token seria um erro.
        return $status === 400
            && in_array('INVALID_ARGUMENT', $errorCodes, true)
            && str_contains(strtolower((string) data_get($body, 'error.message')), 'registration token');
    }

    private function accessToken(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(55), function () {
            $response = Http::asForm()->timeout(10)->post($this->tokenUri(), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt(),
            ]);

            if (!$response->successful() || !$response->json('access_token')) {
                throw new RuntimeException('FCM: não foi possível obter access token OAuth2 (' . $response->status() . '): ' . $response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    private function signedJwt(): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->tokenUri(),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $unsigned = $this->base64Url((string) json_encode($header)) . '.' . $this->base64Url((string) json_encode($claims));

        $key = openssl_pkey_get_private($this->credentials['private_key']);
        if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM: a chave privada da conta de serviço é inválida.');
        }

        return $unsigned . '.' . $this->base64Url($signature);
    }

    private function tokenUri(): string
    {
        return $this->credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    }

    private function cacheKey(): string
    {
        return 'fcm_access_token:' . $this->credentials['client_email'];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
