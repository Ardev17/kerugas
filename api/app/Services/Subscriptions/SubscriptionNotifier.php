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



    /**
     * A presença expirou e começou a contagem (Novembro 2026). Este aviso é o
     * que dá sentido à tolerância: sem ele o posto desaparecia na mesma, só
     * que uns dias depois e sem ninguém ter dito nada.
     */
    public function listingGraceStarted(Station $station, \Illuminate\Support\Carbon $saiEm): bool
    {
        $dias = max(1, (int) ceil(now()->diffInHours($saiEm) / 24));

        return $this->send($station, 'listing_grace',
            'Presença a terminar',
            "A presença do posto {$station->name} na aplicação expirou. "
            . "Tem {$dias} " . ($dias === 1 ? 'dia' : 'dias')
            . " para renovar; a partir de {$saiEm->format('d/m')} o posto deixa de aparecer no mapa e nas pesquisas. "
            . $this->howToPay());
    }

    /**
     * O administrador abriu os pedidos a este posto sem subscrição paga
     * (Novembro 2026). Não se fala em pagamento: foi uma autorização.
     */
    public function ordersAllowedByAdmin(Station $station, ?string $reason): bool
    {
        return $this->send($station, 'orders_allowed',
            'Pedidos activados',
            "Boas notícias! O posto {$station->name} já pode receber os pedidos do município."
            . ($reason ? " Nota da KeruGás: {$reason}" : ''));
    }

    /** O administrador travou os pedidos deste posto. */
    public function ordersBlockedByAdmin(Station $station, ?string $reason): bool
    {
        return $this->send($station, 'orders_blocked',
            'Pedidos suspensos',
            "Olá, caro operador. Os pedidos do município deixaram de entrar no posto {$station->name}."
            . ($reason ? " Motivo: {$reason}" : '')
            . ' Fale connosco para resolver. ' . $this->howToPay());
    }

    /** Voltou a mandar a subscrição: o posto recebe se, e enquanto, estiver paga. */
    public function ordersBackToSubscription(Station $station, bool $subscriptionActive): bool
    {
        return $this->send($station, 'orders_inherit',
            $subscriptionActive ? 'Pedidos conforme a subscrição' : 'Pedidos dependem da subscrição',
            $subscriptionActive
                ? "O posto {$station->name} continua a receber pedidos, agora pela sua subscrição activa."
                : "O posto {$station->name} só volta a receber pedidos com a subscrição activa. " . $this->howToPay());
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

    /** Pedidos do município (Novembro 2026): avisa gerentes e operadores do posto. */
    public function stationOrdersActivated(Station $station, Subscription $subscription): bool
    {
        $until = $subscription->expires_at->copy()
            ->setTimezone(config('kerugas.notifications.timezone', 'Africa/Luanda'))
            ->format('d/m/Y H:i');
        $municipality = $station->municipality?->name ?? 'do seu município';

        return $this->send($station, 'station_orders_activated',
            'Pedidos do município ativos',
            "O posto {$station->name} recebe os pedidos de gás de {$municipality} até {$until}. Quem aceitar primeiro fica com o pedido.");
    }

    public function stationOrdersExpiringSoon(Station $station, Subscription $subscription): bool
    {
        $until = $subscription->expires_at->copy()
            ->setTimezone(config('kerugas.notifications.timezone', 'Africa/Luanda'))
            ->format('d/m/Y H:i');

        return $this->send($station, 'station_orders_expiring',
            'A subscrição de pedidos está a terminar',
            "O posto {$station->name} deixa de receber pedidos do município a {$until}. Renove para continuar. "
            . $this->howToPay());
    }

    private function howToPay(): string
    {
        return 'Para renovar, peça o IBAN pelo ' . $this->settings->contact() . ' e envie o comprovativo.';
    }

    private function send(Station $station, string $event, string $title, string $body): bool
    {
        // Todos os avisos de subscrição vão ao posto inteiro (Novembro 2026):
        // vários operadores (cada um ao painel /operator) e também os gerentes
        // (ao painel /posto). Antes só os operadores recebiam estes avisos.
        $destinatarios = [];
        foreach ($station->operatorIds() as $id) {
            $destinatarios[(int) $id] = '/operator';
        }
        foreach ($station->managers()->pluck('id') as $id) {
            $destinatarios[(int) $id] = '/posto'; // se alguém for operador e gerente, fica com o painel de gerente
        }
        if ($destinatarios === []) {
            return false;
        }

        $any = false;
        foreach ($destinatarios as $userId => $path) {
            try {
                AppNotification::create([
                    'user_id' => $userId,
                    'category' => NotificationCategory::SYSTEM,
                    'title' => $title,
                    'body' => $body,
                    'data' => [
                        'type' => 'subscription',
                        'event' => $event,
                        'station_id' => $station->id,
                        'path' => $path,
                    ],
                ]);
                $any = true;
            } catch (Throwable $e) {
                Log::channel('single')->warning('[SubscriptionNotifier] Falha ao notificar o posto (não crítico)', [
                    'station_id' => $station->id,
                    'event' => $event,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $any;
    }
}
