<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebPushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inscrição de navegadores para Web Push (Setembro 2026). Como os
 * telemóveis do app (DeviceTokenController), cada inscrição fica ligada à
 * sessão com que foi feita — ao terminar sessão, esse navegador deixa de
 * receber notificações desta conta.
 */
class WebPushSubscriptionController extends Controller
{
    /** Chave pública VAPID — o site precisa dela para pedir a inscrição. */
    public function publicKey(WebPushSender $sender): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $sender->isEnabled(),
                'public_key' => $sender->isEnabled() ? config('kerugas.push.vapid_public_key') : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Só serviços de push reais por HTTPS (Chrome/Edge, Firefox, Safari).
            'endpoint' => ['required', 'url:https', 'max:1000', function (string $attribute, mixed $value, \Closure $fail) {
                if (!is_string($value) || !self::isKnownPushService($value)) {
                    $fail('Serviço de notificações do navegador não reconhecido.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        $accessToken = $request->user()->currentAccessToken();

        WebPushSubscription::upsert([[
            'user_id' => $request->user()->id,
            'personal_access_token_id' => $accessToken->id ?? null,
            'endpoint' => $data['endpoint'],
            'endpoint_hash' => hash('sha256', $data['endpoint']),
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]], ['endpoint_hash'], ['user_id', 'personal_access_token_id', 'public_key', 'auth_token', 'content_encoding', 'user_agent']);

        return response()->json(['success' => true], 201);
    }

    /**
     * O servidor faz pedidos HTTP para este endereço a cada notificação —
     * aceitar qualquer URL permitiria usá-lo para chegar a serviços
     * internos (SSRF). Só os serviços de push dos navegadores reais:
     * Chrome/Edge Android/Opera/Samsung (FCM), Firefox (Mozilla), Safari
     * (Apple) e Edge no Windows (WNS).
     */
    public const PUSH_SERVICE_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        '.push.services.mozilla.com',
        '.push.apple.com',
        '.notify.windows.com',
    ];

    public static function isKnownPushService(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::PUSH_SERVICE_HOSTS as $allowed) {
            if (str_starts_with($allowed, '.') ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        WebPushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }
}
