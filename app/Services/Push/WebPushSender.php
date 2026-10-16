<?php

namespace App\Services\Push;

use App\Models\OrderPushSubscription;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Web Push no navegador (VAPID), Setembro 2026 — notificações no
 * computador/telemóvel mesmo com o site fechado.
 *
 * Usa a biblioteca `minishlink/web-push` (a de referência em PHP; a
 * cifra do Web Push — ECDH + AES-128-GCM — não é algo a reimplementar à
 * mão). Instalar no servidor com:
 *   composer require minishlink/web-push
 * Sem a biblioteca ou sem as chaves VAPID no .env, fica desligado em
 * silêncio e nada mais muda (o mesmo padrão do PDF com DomPDF).
 */
class WebPushSender
{
    public function isEnabled(): bool
    {
        return class_exists(\Minishlink\WebPush\WebPush::class)
            && config('kerugas.push.vapid_public_key')
            && config('kerugas.push.vapid_private_key');
    }

    /** Devolve false quando a inscrição expirou (e deve ser apagada). */
    public function send(WebPushSubscription|OrderPushSubscription $subscription, PushMessage $message): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => (string) config('kerugas.push.vapid_subject'),
                    'publicKey' => (string) config('kerugas.push.vapid_public_key'),
                    'privateKey' => (string) config('kerugas.push.vapid_private_key'),
                ],
            ]);

            $report = $webPush->sendOneNotification(
                \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                (string) json_encode([
                    'title' => $message->title,
                    'body' => $message->body,
                    'path' => $message->path,
                    'tag' => $message->channel,
                ]),
                ['TTL' => 6 * 3600, 'urgency' => $message->channel === PushMessage::CHANNEL_ORDERS ? 'high' : 'normal']
            );

            if ($report->isSubscriptionExpired()) {
                return false;
            }

            if (!$report->isSuccess()) {
                Log::channel('single')->warning('[WebPushSender] Envio recusado', ['reason' => $report->getReason()]);
            }
        } catch (Throwable $e) {
            Log::channel('single')->warning('[WebPushSender] Falha ao enviar Web Push', ['error' => $e->getMessage()]);
        }

        return true;
    }
}
