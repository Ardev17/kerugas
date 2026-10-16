<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\WebPushSubscription;
use App\Services\Push\ApnsPushSender;
use App\Services\Push\PushMessageFactory;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\Push\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envia uma notificação do app como push para TODOS os telemóveis do
 * destinatário (a mesma pessoa pode ter o app em mais do que um).
 *
 * Recebe só o ID (não o model) e volta a ler da base de dados: se a
 * notificação ou o utilizador tiverem sido apagados entretanto, não há
 * nada a enviar. Tokens que o Firebase declara inválidos (app
 * desinstalado, dados limpos) são apagados aqui mesmo — a tabela
 * limpa-se sozinha.
 */
class SendPushForNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId)
    {
        $this->afterCommit();
    }

    public function handle(PushSender $sender, PushMessageFactory $factory, ?WebPushSender $webPush = null, ?ApnsPushSender $apns = null): void
    {
        $webPush ??= app(WebPushSender::class);
        $apns ??= app(ApnsPushSender::class);

        if (!$sender->isEnabled() && !$webPush->isEnabled() && !$apns->isEnabled()) {
            return;
        }

        $notification = AppNotification::with('user')->find($this->notificationId);
        $recipient = $notification?->user;

        if (!$notification || !$recipient) {
            return;
        }

        $message = $factory->fromNotification($notification, $recipient);

        // Web Push nos navegadores inscritos (Setembro 2026) — mesmo
        // cuidado: cada navegador à parte, e inscrições expiradas apagadas.
        if ($webPush->isEnabled()) {
            WebPushSubscription::where('user_id', $recipient->id)->each(function (WebPushSubscription $subscription) use ($webPush, $message) {
                try {
                    if (!$webPush->send($subscription, $message)) {
                        $subscription->delete();
                    }
                } catch (Throwable $e) {
                    Log::channel('single')->warning('[SendPushForNotificationJob] Falha num navegador (os restantes continuam)', [
                        'web_push_subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        if (!$sender->isEnabled() && !$apns->isEnabled()) {
            return;
        }

        // Cada telemóvel é tratado à parte: uma falha num (ex: erro a
        // apagar um token inválido) não interrompe os outros e, sobretudo,
        // não faz a fila repetir o job — o que reenviaria a push a quem já
        // a tinha recebido. Por isso também só há uma tentativa: o
        // FcmPushSender já trata sozinho as falhas de rede/Google.
        DeviceToken::where('user_id', $recipient->id)->each(function (DeviceToken $device) use ($sender, $apns, $message) {
            // iPhone → APNs (Apple); Android → FCM (Google). Cada um só
            // trata dos seus telemóveis, e só se estiver configurado.
            $channel = $device->platform === 'ios' ? $apns : $sender;
            if (!$channel->isEnabled()) {
                return;
            }

            try {
                if ($channel->send($device->token, $message) === PushResult::INVALID_TOKEN) {
                    $device->delete();
                }
            } catch (Throwable $e) {
                Log::channel('single')->warning('[SendPushForNotificationJob] Falha num telemóvel (os restantes continuam)', [
                    'device_token_id' => $device->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
