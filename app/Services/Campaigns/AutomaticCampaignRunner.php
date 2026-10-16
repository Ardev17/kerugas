<?php

namespace App\Services\Campaigns;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\CampaignDelivery;
use App\Models\GasOrder;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Regras de QUEM recebe cada campanha agendada (Setembro 2026). O
 * agendador (routes/console.php) chama `run('chave')` na hora certa; o
 * CampaignService decide depois se cada envio pode mesmo sair
 * (preferências, horas de silêncio, limite semanal, repetição).
 *
 * Todas as consultas andam em blocos (chunkById), para não carregar
 * milhares de utilizadores de uma vez para a memória.
 */
class AutomaticCampaignRunner
{
    /** Intervalo por omissão entre pedidos, antes de o sistema conhecer a casa. */
    public const DEFAULT_REFILL_DAYS = 18;

    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return [
            'gas_reminder', 'gas_reminder_followup', 'weekend_eve', 'safety_tip', 'welcome_alerts',
            'post_delivery', 'account_anniversary', 'special_dates', 'we_miss_you',
            'operator_stale', 'driver_morning', 'admin_daily_summary',
        ];
    }

    /** Devolve quantas notificações saíram. */
    public function run(string $key): int
    {
        return match ($key) {
            'gas_reminder' => $this->gasReminder(),
            'gas_reminder_followup' => $this->gasReminderFollowup(),
            'weekend_eve' => $this->simpleDaily('weekend_eve', $this->customers()),
            'safety_tip' => $this->safetyTip(),
            'welcome_alerts' => $this->welcomeAlerts(),
            'post_delivery' => $this->postDelivery(),
            'account_anniversary' => $this->accountAnniversary(),
            'special_dates' => $this->specialDates(),
            'we_miss_you' => $this->weMissYou(),
            'operator_stale' => $this->operatorStale(),
            'driver_morning' => $this->simpleDaily('driver_morning', User::query()
                ->where('role', UserRole::DRIVER)->where('status', 'ACTIVE')->where('driver_available', false)),
            'admin_daily_summary' => $this->adminDailySummary(),
            default => throw new InvalidArgumentException("Campanha automática desconhecida: {$key}"),
        };
    }

    /**
     * O lembrete principal. Para quem já pediu: conta desde a ÚLTIMA
     * entrega e usa o ritmo real daquela casa (média dos intervalos entre
     * as últimas entregas), avisando 2 dias antes da data prevista. Para
     * quem nunca pediu: 15 dias depois do registo, uma vez.
     */
    public function gasReminder(): int
    {
        $sent = 0;
        $today = $this->today();

        $this->customers()->chunkById(200, function ($users) use (&$sent, $today) {
            foreach ($users as $user) {
                $deliveries = GasOrder::where('user_id', $user->id)
                    ->where('status', GasOrderStatus::DELIVERED)
                    ->whereNotNull('completed_at')
                    ->latest('completed_at')
                    ->limit(4)
                    ->get(['id', 'completed_at']);

                if ($deliveries->isEmpty()) {
                    if ($user->created_at && $user->created_at->lte($today->copy()->subDays(15))
                        && !$this->hasOpenOrder($user->id)) {
                        $days = (int) $user->created_at->diffInDays($today);
                        $sent += (int) (bool) $this->campaigns->deliver('gas_reminder', $user, ['dias' => $days], "gas_reminder:{$user->id}:signup");
                    }
                    continue;
                }

                $last = $deliveries->first();
                $due = $last->completed_at->copy()->addDays($this->refillDays($deliveries) - 2);

                // Já pediu outra vez depois da última entrega? Então não
                // está sem gás — nada a lembrar.
                if ($today->lt($due) || $this->hasOpenOrder($user->id, $last->completed_at)) {
                    continue;
                }

                $days = (int) $last->completed_at->diffInDays($today);
                $sent += (int) (bool) $this->campaigns->deliver('gas_reminder', $user, ['dias' => $days], "gas_reminder:{$user->id}:order:{$last->id}");
            }
        });

        return $sent;
    }

    /** Uma única vez, 3 dias depois do lembrete, se não houve pedido. */
    public function gasReminderFollowup(): int
    {
        $sent = 0;

        CampaignDelivery::with('user')
            ->where('campaign_key', 'gas_reminder')
            ->whereNull('converted_order_id')
            ->whereBetween('sent_at', [now()->subDays(4), now()->subDays(3)])
            ->chunkById(200, function ($deliveries) use (&$sent) {
                foreach ($deliveries as $delivery) {
                    $user = $delivery->user;
                    if (!$user || GasOrder::where('user_id', $user->id)->where('created_at', '>=', $delivery->sent_at)->exists()) {
                        continue;
                    }
                    $sent += (int) (bool) $this->campaigns->deliver('gas_reminder_followup', $user, [], "gas_reminder_followup:{$delivery->id}");
                }
            });

        return $sent;
    }

    public function safetyTip(): int
    {
        $tips = CampaignCatalog::find('safety_tip')['tips'];
        $today = $this->today();
        $tip = $tips[($today->year * 12 + $today->month) % count($tips)];

        return $this->simpleDaily('safety_tip', $this->customers(), ['dica' => $tip], $today->format('Y-m'));
    }

    /** Dois dias depois do registo, só para quem ainda não criou alertas. */
    public function welcomeAlerts(): int
    {
        $day = $this->today()->subDays(2);

        return $this->simpleDaily('welcome_alerts', $this->customers()
            ->whereBetween('created_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->whereDoesntHave('alerts'), [], 'once');
    }

    /** 1 a 3 horas depois de uma entrega feita por condutor, sem avaliação. */
    public function postDelivery(): int
    {
        $sent = 0;

        GasOrder::with(['user', 'assignedUser'])
            ->where('status', GasOrderStatus::DELIVERED)
            ->whereNotNull('user_id')
            ->whereBetween('completed_at', [now()->subHours(3), now()->subHour()])
            ->whereHas('assignedUser', fn (Builder $q) => $q->where('role', UserRole::DRIVER))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('driver_ratings')->whereColumn('driver_ratings.gas_order_id', 'gas_orders.id'))
            ->chunkById(200, function ($orders) use (&$sent) {
                foreach ($orders as $order) {
                    if (!$order->user) {
                        continue;
                    }
                    $sent += (int) (bool) $this->campaigns->deliver('post_delivery', $order->user, [
                        'condutor' => explode(' ', trim((string) $order->assignedUser?->name))[0] ?: 'condutor',
                        'referencia' => rawurlencode((string) $order->order_reference),
                    ], "post_delivery:{$order->id}");
                }
            });

        return $sent;
    }

    public function accountAnniversary(): int
    {
        $sent = 0;
        $today = $this->today();

        $this->customers()
            ->where('created_at', '<', $today->copy()->subYear()->endOfDay()->utc())
            ->chunkById(200, function ($users) use (&$sent, $today) {
                foreach ($users as $user) {
                    $created = $user->created_at->copy()->setTimezone($today->timezone);
                    if ($created->format('m-d') !== $today->format('m-d')) {
                        continue;
                    }
                    // Anos de calendário: o diffInYears do Carbon 3 devolve
                    // um decimal (0,99…) por horas de diferença no próprio dia.
                    $years = $today->year - $created->year;
                    if ($years < 1) {
                        continue;
                    }
                    $label = $years === 1 ? '1 ano' : $years . ' anos';
                    $sent += (int) (bool) $this->campaigns->deliver('account_anniversary', $user, ['anos' => $label], "anniversary:{$user->id}:{$today->year}");
                }
            });

        return $sent;
    }

    /** Só nas datas das variantes (campo "occasion", DD-MM). */
    public function specialDates(): int
    {
        $definition = $this->campaigns->definition('special_dates');
        $today = $this->today()->format('d-m');
        // array_filter mantém os índices originais (estatísticas por variante).
        $templates = array_filter(
            (array) ($definition['templates'] ?? []),
            fn (array $t) => ($t['occasion'] ?? null) === $today
        );

        if ($templates === []) {
            return 0;
        }

        $sent = 0;
        $year = $this->today()->year;
        $this->customers()->chunkById(200, function ($users) use (&$sent, $templates, $today, $year) {
            foreach ($users as $user) {
                $sent += (int) (bool) $this->campaigns->deliver('special_dates', $user, [], "special:{$user->id}:{$year}:{$today}", ['templates' => $templates]);
            }
        });

        return $sent;
    }

    /**
     * 45 dias sem abrir o KeruGás nem pedir. "Abrir" = o token de sessão
     * foi usado (o app mantém a sessão meses, por isso `last_login_at`
     * sozinho daria falsos inativos). No máximo uma vez a cada 90 dias.
     */
    public function weMissYou(): int
    {
        $cutoff = now()->subDays(45);
        $sent = 0;

        $this->customers()
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('personal_access_tokens')
                ->whereColumn('personal_access_tokens.tokenable_id', 'users.id')
                ->where('personal_access_tokens.tokenable_type', User::class)
                ->where('personal_access_tokens.last_used_at', '>=', $cutoff))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('gas_orders')
                ->whereColumn('gas_orders.user_id', 'users.id')
                ->where('gas_orders.created_at', '>=', $cutoff))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('campaign_deliveries')
                ->whereColumn('campaign_deliveries.user_id', 'users.id')
                ->where('campaign_deliveries.campaign_key', 'we_miss_you')
                ->where('campaign_deliveries.sent_at', '>=', now()->subDays(90)))
            ->chunkById(200, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    $sent += (int) (bool) $this->campaigns->deliver('we_miss_you', $user, [], "we_miss_you:{$user->id}:" . $this->today()->format('Y-m-d'));
                }
            });

        return $sent;
    }

    /** Postos com operador cujo estado não é atualizado há mais de 6 horas. */
    public function operatorStale(): int
    {
        $sent = 0;
        $window = intdiv(now()->timestamp, 6 * 3600);

        Station::with('operators')
            ->where('status', 'ACTIVE')
            ->whereHas('operators')
            ->where(fn (Builder $q) => $q->whereNull('status_updated_at')->orWhere('status_updated_at', '<', now()->subHours(6)))
            ->chunkById(200, function ($stations) use (&$sent, $window) {
                foreach ($stations as $station) {
                    $hours = $station->status_updated_at ? (int) $station->status_updated_at->diffInHours(now()) : 24;
                    // Vários operadores por posto (Outubro 2026): avisa cada um.
                    foreach ($station->operators as $operator) {
                        $sent += (int) (bool) $this->campaigns->deliver('operator_stale', $operator, [
                            'posto' => $station->name,
                            'horas' => $hours,
                        ], "operator_stale:{$station->id}:{$operator->id}:{$window}");
                    }
                }
            });

        return $sent;
    }

    /** Cada Admin vê a sua província; o Super Admin vê tudo. */
    public function adminDailySummary(): int
    {
        $sent = 0;
        $today = $this->today();
        $from = $today->copy()->startOfDay()->utc();
        $to = $today->copy()->endOfDay()->utc();

        User::query()
            ->whereIn('role', UserRole::managementRoles())
            ->where('status', 'ACTIVE')
            ->chunkById(100, function ($admins) use (&$sent, $from, $to, $today) {
                foreach ($admins as $admin) {
                    // Admin e Super Admin Provincial: só a sua província.
                    $scope = fn () => GasOrder::query()->when(
                        $admin->scopedProvinceId() !== null,
                        fn (Builder $q) => $q->where('province_id', $admin->scopedProvinceId())
                    );

                    $sent += (int) (bool) $this->campaigns->deliver('admin_daily_summary', $admin, [
                        'pedidos' => $scope()->whereBetween('created_at', [$from, $to])->count(),
                        'entregues' => $scope()->where('status', GasOrderStatus::DELIVERED)->whereBetween('completed_at', [$from, $to])->count(),
                        'pendentes' => $scope()->whereIn('status', [GasOrderStatus::PENDING, GasOrderStatus::RECEIVED])->count(),
                    ], "admin_summary:{$admin->id}:{$today->toDateString()}");
                }
            });

        return $sent;
    }

    /**
     * Campanha simples: a todas as pessoas da consulta, uma vez por
     * período (por omissão, uma vez por dia).
     *
     * @param  array<string, scalar>  $vars
     */
    private function simpleDaily(string $key, Builder $users, array $vars = [], ?string $period = null): int
    {
        $sent = 0;
        $period ??= $this->today()->toDateString();

        $users->chunkById(200, function ($chunk) use (&$sent, $key, $vars, $period) {
            foreach ($chunk as $user) {
                $sent += (int) (bool) $this->campaigns->deliver($key, $user, $vars, "{$key}:{$user->id}:{$period}");
            }
        });

        return $sent;
    }

    private function customers(): Builder
    {
        return User::query()->where('role', UserRole::USER)->where('status', 'ACTIVE');
    }

    /**
     * Ritmo de cada casa: média dos intervalos entre as últimas entregas,
     * limitada a 7–45 dias (um valor fora disto é quase sempre um pedido
     * extraordinário, não o consumo normal).
     *
     * @param  \Illuminate\Support\Collection<int, GasOrder>  $deliveries  (mais recente primeiro)
     */
    private function refillDays($deliveries): int
    {
        if ($deliveries->count() < 2) {
            return self::DEFAULT_REFILL_DAYS;
        }

        $gaps = [];
        for ($i = 0; $i < $deliveries->count() - 1; $i++) {
            $gaps[] = $deliveries[$i + 1]->completed_at->diffInDays($deliveries[$i]->completed_at);
        }

        return (int) max(7, min(45, round(array_sum($gaps) / count($gaps))));
    }

    /**
     * Sem `$since`: há um pedido EM CURSO (ainda não entregue)?
     * Com `$since`: fez algum pedido (não cancelado) depois dessa data?
     * Em ambos os casos a pessoa não está sem gás — não se lembra nada.
     */
    private function hasOpenOrder(int $userId, ?Carbon $since = null): bool
    {
        $query = GasOrder::where('user_id', $userId)
            ->whereNotIn('status', [GasOrderStatus::CANCELLED, GasOrderStatus::FAILED]);

        return $since
            ? $query->where('created_at', '>', $since)->exists()
            : $query->where('status', '!=', GasOrderStatus::DELIVERED)->exists();
    }

    private function today(): Carbon
    {
        return now()->setTimezone($this->campaigns->timezone());
    }
}
