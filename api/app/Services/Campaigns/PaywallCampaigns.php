<?php

namespace App\Services\Campaigns;

use App\Enums\GasOrderStatus;
use App\Enums\OperationStatus;
use App\Enums\SmsTemplateType;
use App\Enums\StationDataAccessEffect;
use App\Enums\StationStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\Station;
use App\Models\StationDataGrant;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stations\StationListingService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SubscriptionSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Notificações de paywall, consultas grátis, presença e pedidos de postos
 * (Novembro 2026). Cada método devolve quantas notificações saíram; o
 * CampaignService continua a decidir se cada envio sai mesmo (campanha
 * ligada, horas de silêncio, repetição por chave).
 *
 * Todas são idempotentes: a chave de repetição inclui a pessoa e o período
 * (dia, semana, mês), por isso correr duas vezes não envia duas vezes.
 */
class PaywallCampaigns
{
    /** Minutos que um pedido pode esperar antes de se avisar os postos. */
    public const UNANSWERED_AFTER_MINUTES = 15;

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionSettings $settings,
        private readonly StationListingService $listing,
    ) {
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return [
            'municipality_paywall_soon', 'municipality_paywall_started',
            'free_consults_low', 'free_consults_exhausted', 'free_consults_renewed',
            'sms_subscription_expiring',
            'station_weekly_summary', 'order_unanswered', 'orders_missed', 'listing_invite',
            'admin_subscriptions_expiring', 'admin_grace_ending', 'admin_municipality_uncovered',
        ];
    }

    public function run(string $key): int
    {
        return match ($key) {
            'municipality_paywall_soon' => $this->municipalityPaywall('municipality_paywall_soon', now(), now()->addDays(3)),
            'municipality_paywall_started' => $this->municipalityPaywall('municipality_paywall_started', now()->subDay(), now()),
            'free_consults_low' => $this->freeConsults('free_consults_low'),
            'free_consults_exhausted' => $this->freeConsults('free_consults_exhausted'),
            'free_consults_renewed' => $this->freeConsultsRenewed(),
            'sms_subscription_expiring' => $this->smsSubscriptionExpiring(),
            'station_weekly_summary' => $this->stationWeeklySummary(),
            'order_unanswered' => $this->orderUnanswered(),
            'orders_missed' => $this->ordersMissed(),
            'listing_invite' => $this->listingInvite(),
            'admin_subscriptions_expiring' => $this->adminSubscriptionsExpiring(),
            'admin_grace_ending' => $this->adminGraceEnding(),
            'admin_municipality_uncovered' => $this->adminMunicipalityUncovered(),
        };
    }

    // ------------------------------------------------------------- clientes

    /**
     * Municípios que fecham (ou fecharam) entre `$from` e `$to`: avisa os
     * clientes de lá, uma vez por pessoa e por data de fecho. Quem tem uma
     * excepção ALLOW não é afectado e não recebe o aviso.
     */
    public function municipalityPaywall(string $key, Carbon $from, Carbon $to): int
    {
        $sent = 0;
        $allowed = StationDataGrant::query()
            ->where('effect', StationDataAccessEffect::ALLOW->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('user_id');

        Municipality::query()
            ->whereNotNull('station_data_blocked_from')
            ->whereBetween('station_data_blocked_from', [$from, $to])
            ->get()
            ->each(function (Municipality $municipality) use ($key, $allowed, &$sent) {
                $date = $municipality->station_data_blocked_from->copy()->setTimezone($this->campaigns->timezone());

                User::query()
                    ->where('role', UserRole::USER)->where('status', 'ACTIVE')
                    ->where('municipality_id', $municipality->id)
                    ->whereNotIn('id', $allowed)
                    ->chunkById(200, function ($users) use ($key, $municipality, $date, &$sent) {
                        foreach ($users as $user) {
                            $sent += (int) (bool) $this->campaigns->deliver($key, $user, [
                                'municipio' => $municipality->name,
                                'data' => $date->format('d/m'),
                            ], "{$key}:{$user->id}:{$municipality->id}:{$date->toDateString()}");
                        }
                    });
            });

        return $sent;
    }

    /**
     * `free_consults_low`: resta exactamente uma consulta. `free_consults_exhausted`:
     * já usou todas. Só com o limite ligado e só a quem não tem subscrição.
     */
    public function freeConsults(string $key): int
    {
        $limit = $this->settings->freeConsults();
        if (!$this->settings->enforcementEnabled() || $limit < 1) {
            return 0;
        }

        $exhausted = $key === 'free_consults_exhausted';
        if (!$exhausted && $limit < 2) {
            return 0;
        }

        $from = $this->subscriptions->monthStart();
        $month = $this->today()->format('Y-m');
        $sent = 0;

        $rows = $this->consultCounts($from, null, $exhausted ? $limit : $limit - 1);

        foreach ($rows as $phone => $used) {
            if (!$exhausted && $used >= $limit) {
                continue;
            }
            if ($this->subscriptions->activeSmsSubscription($phone)) {
                continue;
            }
            $user = $this->customerByPhone($phone);
            if (!$user) {
                continue;
            }
            $sent += (int) (bool) $this->campaigns->deliver($key, $user, [
                'restantes' => max(0, $limit - $used),
                'limite' => $limit,
            ], "{$key}:{$user->id}:{$month}");
        }

        return $sent;
    }

    /** Dia 1: quem esgotou as consultas no mês anterior (e não subscreveu) já as tem de volta. */
    public function freeConsultsRenewed(): int
    {
        $limit = $this->settings->freeConsults();
        if (!$this->settings->enforcementEnabled() || $limit < 1) {
            return 0;
        }

        $thisMonth = $this->subscriptions->monthStart();
        $lastMonth = $this->subscriptions->monthStart($this->today()->startOfMonth()->subDay());
        $month = $this->today()->format('Y-m');
        $sent = 0;

        foreach ($this->consultCounts($lastMonth, $thisMonth, $limit) as $phone => $used) {
            if ($this->subscriptions->activeSmsSubscription($phone)) {
                continue;
            }
            $user = $this->customerByPhone($phone);
            if ($user) {
                $sent += (int) (bool) $this->campaigns->deliver('free_consults_renewed', $user, ['limite' => $limit],
                    "free_consults_renewed:{$user->id}:{$month}");
            }
        }

        return $sent;
    }

    /** Subscrição de consultas por SMS que termina nos próximos 5 dias e não foi renovada. */
    public function smsSubscriptionExpiring(): int
    {
        $sent = 0;

        Subscription::query()
            ->covering()
            ->where('type', SubscriptionType::SMS_CONSULT)
            ->where('expires_at', '<=', now()->addDays(5))
            ->get()
            ->each(function (Subscription $s) use (&$sent) {
                $renewed = Subscription::query()
                    ->where('type', SubscriptionType::SMS_CONSULT)
                    ->where('phone', $s->phone)
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->where('expires_at', '>', $s->expires_at)
                    ->exists();
                $user = $renewed ? null : $this->customerByPhone((string) $s->phone);
                if (!$user) {
                    return;
                }
                $until = $s->expires_at->copy()->setTimezone($this->campaigns->timezone())->format('d/m/Y');
                $sent += (int) (bool) $this->campaigns->deliver('sms_subscription_expiring', $user, ['validade' => $until],
                    "sms_subscription_expiring:{$s->id}");
            });

        return $sent;
    }

    // ---------------------------------------------------------------- postos

    /** Segunda-feira: balanço da semana de cada posto com pessoal. */
    public function stationWeeklySummary(): int
    {
        $sent = 0;
        $week = $this->week();
        $since = now()->subDays(7);

        $this->stations()->chunkById(100, function ($stations) use (&$sent, $week, $since) {
            foreach ($stations as $station) {
                $accepted = GasOrder::where('station_id', $station->id)->where('accepted_at', '>=', $since)->count();
                $delivered = GasOrder::where('station_id', $station->id)
                    ->where('status', GasOrderStatus::DELIVERED)->where('completed_at', '>=', $since)->count();
                $missed = $this->missedOrders($station, $since);

                if ($accepted + $delivered + $missed === 0) {
                    continue;
                }

                foreach ($this->staff($station) as $user) {
                    $sent += (int) (bool) $this->campaigns->deliver('station_weekly_summary', $user, [
                        'posto' => $station->name, 'aceites' => $accepted, 'entregues' => $delivered,
                        'perdidos' => $missed, 'caminho' => $this->pathFor($user),
                    ], "station_weekly_summary:{$station->id}:{$user->id}:{$week}");
                }
            }
        });

        return $sent;
    }

    /** Pedidos do município à espera há mais de 15 minutos, para os postos que os podem aceitar. */
    public function orderUnanswered(): int
    {
        $sent = 0;
        $window = intdiv(now()->timestamp, 2 * 3600);

        $waiting = GasOrder::query()
            ->whereNull('station_id')
            ->whereIn('status', [GasOrderStatus::PENDING, GasOrderStatus::RECEIVED])
            ->where('created_at', '<=', now()->subMinutes(self::UNANSWERED_AFTER_MINUTES))
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('municipality_id')
            ->selectRaw('municipality_id, count(*) as total')
            ->groupBy('municipality_id')
            ->pluck('total', 'municipality_id');

        if ($waiting->isEmpty()) {
            return 0;
        }

        $this->stations()->whereIn('municipality_id', $waiting->keys())->with('municipality:id,name')
            ->chunkById(100, function ($stations) use (&$sent, $waiting, $window) {
                foreach ($stations as $station) {
                    if (!$station->canReceiveOrders($this->subscriptions->activeStationOrdersSubscription($station) !== null)) {
                        continue;
                    }
                    foreach ($this->staff($station) as $user) {
                        $sent += (int) (bool) $this->campaigns->deliver('order_unanswered', $user, [
                            'posto' => $station->name, 'municipio' => $station->municipality?->name ?? '',
                            'pedidos' => $waiting[$station->municipality_id], 'caminho' => $this->pathFor($user),
                        ], "order_unanswered:{$station->id}:{$user->id}:{$window}");
                    }
                }
            });

        return $sent;
    }

    /** Segunda-feira: postos que não recebem pedidos e viram o município ser servido por outros. */
    public function ordersMissed(): int
    {
        $sent = 0;
        $week = $this->week();
        $since = now()->subDays(7);

        $this->stations()->with('municipality:id,name')->chunkById(100, function ($stations) use (&$sent, $week, $since) {
            foreach ($stations as $station) {
                if ($station->canReceiveOrders($this->subscriptions->activeStationOrdersSubscription($station) !== null)) {
                    continue;
                }
                $missed = $this->missedOrders($station, $since);
                if ($missed < 1) {
                    continue;
                }
                foreach ($this->staff($station) as $user) {
                    $sent += (int) (bool) $this->campaigns->deliver('orders_missed', $user, [
                        'posto' => $station->name, 'perdidos' => $missed,
                        'municipio' => $station->municipality?->name ?? '', 'caminho' => $this->pathFor($user),
                        'central' => $this->settings->contact(),
                    ], "orders_missed:{$station->id}:{$user->id}:{$week}");
                }
            }
        });

        return $sent;
    }

    /** Quarta-feira: postos fora do mapa (só com a presença paga ligada). */
    public function listingInvite(): int
    {
        if (!$this->listing->enforced()) {
            return 0;
        }

        $sent = 0;
        $week = $this->week();

        $this->stations()->with('municipality:id,name')->chunkById(100, function ($stations) use (&$sent, $week) {
            foreach ($stations as $station) {
                if ($this->listing->isListed($station)) {
                    continue;
                }
                $others = Station::query()->where('status', StationStatus::ACTIVE)
                    ->where('municipality_id', $station->municipality_id)
                    ->whereNotIn('id', $this->listing->hiddenStationIds())->count();

                foreach ($this->staff($station) as $user) {
                    $sent += (int) (bool) $this->campaigns->deliver('listing_invite', $user, [
                        'posto' => $station->name, 'quantidade' => $others,
                        'municipio' => $station->municipality?->name ?? '', 'caminho' => $this->pathFor($user),
                        'central' => $this->settings->contact(),
                    ], "listing_invite:{$station->id}:{$user->id}:{$week}");
                }
            }
        });

        return $sent;
    }

    // ----------------------------------------------------------------- admin

    public function adminSubscriptionsExpiring(): int
    {
        $sent = 0;
        $week = $this->week();

        $this->admins()->chunkById(100, function ($admins) use (&$sent, $week) {
            foreach ($admins as $admin) {
                $total = Subscription::query()
                    ->covering()
                    ->whereIn('type', SubscriptionType::stationValues())
                    ->where('expires_at', '<=', now()->addDays(7))
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscriptions as n')
                        ->whereColumn('n.station_id', 'subscriptions.station_id')
                        ->whereColumn('n.type', 'subscriptions.type')
                        ->where('n.status', Subscription::STATUS_ACTIVE)
                        ->whereColumn('n.expires_at', '>', 'subscriptions.expires_at'))
                    ->when($admin->scopedProvinceId() !== null, fn (Builder $q) => $q->whereHas(
                        'station', fn ($s) => $s->where('province_id', $admin->scopedProvinceId())))
                    ->count();

                if ($total > 0) {
                    $sent += (int) (bool) $this->campaigns->deliver('admin_subscriptions_expiring', $admin, ['total' => $total],
                        "admin_subscriptions_expiring:{$admin->id}:{$week}");
                }
            }
        });

        return $sent;
    }

    /** Postos em tolerância que saem do mapa nas próximas 48 horas. */
    public function adminGraceEnding(): int
    {
        if (!$this->listing->enforced()) {
            return 0;
        }

        $ending = Station::query()->where('status', StationStatus::ACTIVE)->where('platform_owned', false)
            ->whereHas('subscriptions', fn ($q) => $q->where('type', SubscriptionType::STATION_LISTING)
                ->where('status', Subscription::STATUS_ACTIVE)->where('expires_at', '<=', now()))
            ->get()
            ->filter(fn (Station $s) => ($out = $this->listing->toleranciaAcaba($s)) !== null && $out->between(now(), now()->addHours(48)));

        if ($ending->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $day = $this->today()->toDateString();

        $this->admins()->chunkById(100, function ($admins) use (&$sent, $ending, $day) {
            foreach ($admins as $admin) {
                $mine = $admin->scopedProvinceId() === null
                    ? $ending : $ending->where('province_id', $admin->scopedProvinceId());
                if ($mine->isEmpty()) {
                    continue;
                }
                $names = $mine->take(3)->pluck('name')->implode(', ') . ($mine->count() > 3 ? '…' : '');
                $sent += (int) (bool) $this->campaigns->deliver('admin_grace_ending', $admin,
                    ['total' => $mine->count(), 'postos' => $names], "admin_grace_ending:{$admin->id}:{$day}");
            }
        });

        return $sent;
    }

    /** Municípios com pedidos na semana mas sem nenhum posto activo a poder recebê-los. */
    public function adminMunicipalityUncovered(): int
    {
        $ids = GasOrder::query()->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('municipality_id')->distinct()->pluck('municipality_id');

        $uncovered = Municipality::query()->whereIn('id', $ids)->get()->filter(function (Municipality $m) {
            return !Station::query()->where('status', StationStatus::ACTIVE)->where('municipality_id', $m->id)->get()
                ->contains(fn (Station $s) => $s->canReceiveOrders($this->subscriptions->activeStationOrdersSubscription($s) !== null));
        });

        if ($uncovered->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $week = $this->week();

        $this->admins()->chunkById(100, function ($admins) use (&$sent, $uncovered, $week) {
            foreach ($admins as $admin) {
                $mine = $admin->scopedProvinceId() === null
                    ? $uncovered : $uncovered->where('province_id', $admin->scopedProvinceId());
                if ($mine->isEmpty()) {
                    continue;
                }
                $names = $mine->take(3)->pluck('name')->implode(', ') . ($mine->count() > 3 ? '…' : '');
                $sent += (int) (bool) $this->campaigns->deliver('admin_municipality_uncovered', $admin,
                    ['total' => $mine->count(), 'municipios' => $names], "admin_municipality_uncovered:{$admin->id}:{$week}");
            }
        });

        return $sent;
    }

    // ----------------------------------------------------------------- apoio

    /** Pedidos do município na janela que este posto não recebeu (foram para outros). */
    private function missedOrders(Station $station, Carbon $since): int
    {
        if ($station->canReceiveOrders($this->subscriptions->activeStationOrdersSubscription($station) !== null)) {
            return 0;
        }

        return GasOrder::query()->where('municipality_id', $station->municipality_id)
            ->whereNotNull('station_id')->where('station_id', '!=', $station->id)
            ->where('accepted_at', '>=', $since)->count();
    }

    /** @return array<string, int> telefone => consultas gastas no intervalo */
    private function consultCounts(Carbon $from, ?Carbon $to, int $atLeast): array
    {
        return Operation::query()
            ->selectRaw('phone, count(*) as total')
            ->where('status', OperationStatus::SENT)
            ->where('updated_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('updated_at', '<', $to))
            ->whereHas('template', fn ($q) => $q->whereIn('type', SmsTemplateType::consultValues()))
            ->groupBy('phone')
            ->havingRaw('count(*) >= ?', [$atLeast])
            ->pluck('total', 'phone')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function customerByPhone(string $phone): ?User
    {
        return User::query()->where('role', UserRole::USER)->where('status', 'ACTIVE')->where('phone', $phone)->first();
    }

    /** Postos activos com pessoal (gerente ou operador). */
    private function stations(): Builder
    {
        return Station::query()->where('status', StationStatus::ACTIVE)
            ->where(fn ($q) => $q->whereHas('managers')->orWhereIn('id', User::query()
                ->whereNotNull('operated_station_id')->select('operated_station_id')));
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function staff(Station $station)
    {
        return User::query()->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('operated_station_id', $station->id)->orWhere('managed_station_id', $station->id))
            ->get();
    }

    private function pathFor(User $user): string
    {
        return $user->role === UserRole::STATION_MANAGER ? '/posto' : '/operator';
    }

    private function admins(): Builder
    {
        return User::query()->whereIn('role', UserRole::managementRoles())->where('status', 'ACTIVE');
    }

    private function week(): string
    {
        return $this->today()->format('o-\WW');
    }

    private function today(): Carbon
    {
        return now()->setTimezone($this->campaigns->timezone());
    }
}
