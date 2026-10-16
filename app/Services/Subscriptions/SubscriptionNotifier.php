<?php

namespace App\Services\Subscriptions;

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\Station;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos AUTOMÁTICOS ao operador de um posto sobre analytics e subscrição.
 * Cria uma notificação dentro da plataforma; o push (app e navegador) sai
 * sozinho dela (AppNotificationObserver). "Melhor esforço": uma falha aqui
 * nunca pode impedir a ação do Super Admin que já ficou gravada.
 *
 * O texto vive aqui (não no `sms_templates`): é para notificação, sem o
 * limite de 160 carateres do SMS. O aviso por SMS ao operador é manual e
 * usa o template OPERATOR_ANALYTICS da Central.
 */
class SubscriptionNotifier
{
    public function __construct(private readonly SubscriptionSettings $settings)
    {
    }

    public function analyticsDisabled(Station $station): bool
    {
        return $this->send($station, 'analytics_disabled',
            'Analytics desativado',
            "Olá, caro operador. O analytics do posto {$station->name} está desativado. "
            . 'Atualize a subscrição para ter a versão completa disponível. '
            . $this->howToPay());
    }

    public function analyticsEnabled(Station $station): bool
    {
        return $this->send($station, 'analytics_enabled',
            'Analytics ativado',
            "Boas notícias! O analytics do posto {$station->name} voltou a estar disponível. Já pode ver a versão completa.");
    }

    public function subscriptionActivated(Station $station, Subscription $subscription, bool $analyticsEnabledNow = false): bool
    {
        $until = $subscription->expires_at->copy()
            ->setTimezone(config('kerugas.notifications.timezone', 'Africa/Luanda'))
            ->format('d/m/Y');

        return $this->send($station, 'subscription_activated',
            'Subscrição ativada',
            "A subscrição do posto {$station->name} está ativa até {$until}. Obrigado!"
            . ($analyticsEnabledNow ? ' O analytics do posto já está disponível.' : ''));
    }

    public function expiringSoon(Station $station, Subscription $subscription): bool
    {
        $until = $subscription->expires_at->copy()
            ->setTimezone(config('kerugas.notifications.timezone', 'Africa/Luanda'))
            ->format('d/m/Y');

        return $this->send($station, 'subscription_expiring',
            'A subscrição está a terminar',
            "A subscrição do posto {$station->name} termina a {$until}. Renove para não perder a versão completa. "
            . $this->howToPay());
    }

    private function howToPay(): string
    {
        return 'Para renovar, peça o IBAN pelo ' . $this->settings->contact() . ' e envie o comprovativo.';
    }

    private function send(Station $station, string $event, string $title, string $body): bool
    {
        // Vários operadores por posto (Outubro 2026): todos recebem o aviso.
        $operatorIds = $station->operatorIds();
        if ($operatorIds === []) {
            return false;
        }

        $any = false;
        foreach ($operatorIds as $operatorId) {
            try {
                AppNotification::create([
                    'user_id' => $operatorId,
                    'category' => NotificationCategory::SYSTEM,
                    'title' => $title,
                    'body' => $body,
                    'data' => [
                        'type' => 'subscription',
                        'event' => $event,
                        'station_id' => $station->id,
                        'path' => '/operator',
                    ],
                ]);
                $any = true;
            } catch (Throwable $e) {
                Log::channel('single')->warning('[SubscriptionNotifier] Falha ao notificar o operador (não crítico)', [
                    'station_id' => $station->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $any;
    }
}
