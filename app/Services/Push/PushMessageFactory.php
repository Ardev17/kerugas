<?php

namespace App\Services\Push;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\User;

/**
 * Traduz uma notificação do app (AppNotification) na push certa para
 * quem a recebe — em particular, QUE ECRÃ abre ao tocar. O mesmo
 * "novo pedido" leva um Admin ao painel de pedidos e um Condutor ao
 * seu painel de entregas.
 */
class PushMessageFactory
{
    public function fromNotification(AppNotification $notification, User $recipient): PushMessage
    {
        $data = (array) ($notification->data ?? []);
        $category = $notification->category instanceof NotificationCategory
            ? $notification->category
            : NotificationCategory::tryFrom((string) $notification->category);

        [$path, $channel] = match ($category) {
            NotificationCategory::ORDER => [$this->orderPath($recipient, $data), PushMessage::CHANNEL_ORDERS],
            NotificationCategory::AVAILABILITY, NotificationCategory::QUEUE, NotificationCategory::FAVORITE => [
                isset($data['station_id']) ? '/postos/' . (int) $data['station_id'] : '/mapa',
                PushMessage::CHANNEL_ALERTS,
            ],
            NotificationCategory::REMINDER => ['/', PushMessage::CHANNEL_REMINDERS],
            NotificationCategory::TIP => ['/', PushMessage::CHANNEL_TIPS],
            NotificationCategory::PROMO => ['/', PushMessage::CHANNEL_PROMOS],
            default => ['/', PushMessage::CHANNEL_GENERAL],
        };

        // Campanhas (Setembro 2026) definem elas próprias o ecrã a abrir e
        // o canal Android (lembretes, dicas, promoções...). Só caminhos
        // internos simples são aceites — o app volta a validar antes de
        // navegar (src/native/deepLinks.ts).
        $customPath = $data['path'] ?? null;
        if (is_string($customPath) && preg_match('#^/(?!/)[^\\\\\s\x00-\x1F\x7F]*$#', $customPath)) {
            $path = $customPath;
        }
        // Popup (Outubro 2026): uma notificação sem ecrã próprio abre o app com
        // a mensagem completa num popup, em vez de cair na página inicial.
        if ($path === '/' && $notification->id) {
            $path = '/?notificacao=' . (int) $notification->id;
        }

        $allowedChannels = [
            PushMessage::CHANNEL_ORDERS, PushMessage::CHANNEL_ALERTS, PushMessage::CHANNEL_GENERAL,
            PushMessage::CHANNEL_REMINDERS, PushMessage::CHANNEL_TIPS, PushMessage::CHANNEL_PROMOS,
        ];
        if (isset($data['channel']) && in_array($data['channel'], $allowedChannels, true)) {
            $channel = $data['channel'];
        }

        return new PushMessage(
            title: (string) $notification->title,
            body: (string) $notification->body,
            path: $path,
            channel: $channel,
            data: ['notification_id' => (string) $notification->id],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function orderPath(User $recipient, array $data): string
    {
        return match ($recipient->role) {
            UserRole::DRIVER => '/driver',
            UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN => '/admin/orders',
            // Cliente com conta: a página de acompanhamento do próprio
            // pedido, pela referência pública (nunca pelo ID interno).
            default => isset($data['reference'])
                ? '/pedido/' . rawurlencode((string) $data['reference'])
                : '/dashboard',
        };
    }
}
