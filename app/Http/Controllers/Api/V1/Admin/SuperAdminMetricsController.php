<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\DriverRating;
use App\Models\GasOrder;
use App\Models\OrderPushSubscription;
use App\Models\Station;
use App\Models\User;
use App\Models\WebPushSubscription;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Painel de métricas do SUPER ADMIN (Outubro 2026) — a visão do negócio
 * inteiro numa página: pedidos, tempos de resposta, faturação, condutores,
 * avaliações, postos, utilizadores e saúde das notificações.
 *
 * Só SUPER_ADMIN e PROVINCE_SUPER_ADMIN (rota `role:`): mistura dados
 * financeiros que um Admin não deve ver. O Super Admin Provincial (Outubro
 * 2026) vê exatamente o mesmo painel, mas filtrado pela SUA província —
 * pedidos, condutores, avaliações, postos, utilizadores e notificações.
 *
 * DEFINIÇÕES (para os números baterem certo com o que se vê):
 *  - Tudo o que é "pedidos" conta os pedidos CRIADOS dentro do período
 *    (coorte) e o estado em que estão AGORA. Assim, "taxa de conclusão"
 *    = entregues ÷ criados no período, sem misturar períodos.
 *  - Faturação = soma do preço de entrega dos pedidos ENTREGUES dessa
 *    coorte (o preço só existe depois de o Admin confirmar).
 *  - Tempos em minutos: "até ao 1.º contacto" = criação → `contacted_at`;
 *    "até à entrega" = criação → `completed_at`. Só entram pedidos que
 *    chegaram a esse passo (um pedido ainda pendente não conta como 0).
 *  - "Período anterior" = os N dias imediatamente antes, para as setas de
 *    tendência.
 *
 * Consultas portáteis (DATE(), COUNT, SUM, AVG) — correm igual em MySQL e
 * em SQLite (testes). As durações calculam-se em PHP, com cursor, para não
 * depender de funções de data específicas de cada base de dados.
 */
class SuperAdminMetricsController extends Controller
{
    private const ALLOWED_DAYS = [7, 30, 90];

    /** null = país inteiro (Super Admin geral); id = só esta província. */
    private ?int $province = null;

    public function index(Request $request): JsonResponse
    {
        $this->province = $request->user()->scopedProvinceId();

        $days = (int) $request->query('days', 30);
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }

        $until = now();
        $since = now()->subDays($days - 1)->startOfDay();
        $previousSince = $since->copy()->subDays($days);
        $previousUntil = $since->copy()->subSecond();

        return response()->json([
            'success' => true,
            'data' => [
                'scope' => $this->province === null ? 'all' : 'province',
                'period' => [
                    'days' => $days,
                    'since' => $since->toDateString(),
                    'until' => $until->toDateString(),
                ],
                'orders' => $this->orderKpis($since, $until),
                'previous_orders' => $this->orderCounters($previousSince, $previousUntil),
                'orders_by_day' => $this->ordersByDay($since, $days),
                'orders_by_status' => $this->ordersByStatus($since),
                'orders_by_province' => $this->ordersByProvince($since),
                'funnel' => $this->funnel($since),
                'drivers' => $this->drivers($since),
                'ratings' => $this->ratings($since),
                'stations' => $this->stations(),
                'users' => $this->users($since),
                'notifications' => $this->notifications($since),
            ],
        ]);
    }

    private function orders()
    {
        return GasOrder::query()->when($this->province !== null, fn ($q) => $q->where('gas_orders.province_id', $this->province));
    }

    private function people()
    {
        return User::query()->when($this->province !== null, fn ($q) => $q->where('province_id', $this->province));
    }

    private function inProvinceUsers(string $column = 'user_id')
    {
        return fn ($q) => $this->province === null ? $q : $q->whereIn($column, User::where('province_id', $this->province)->select('id'));
    }

    /** Contadores simples de uma janela (usado também para o período anterior). */
    private function orderCounters(CarbonInterface $from, CarbonInterface $to): array
    {
        $base = $this->orders()->whereBetween('created_at', [$from, $to]);

        $total = (clone $base)->count();
        $delivered = (clone $base)->where('status', GasOrderStatus::DELIVERED)->count();

        return [
            'total' => $total,
            'delivered' => $delivered,
            'revenue' => (float) (clone $base)->where('status', GasOrderStatus::DELIVERED)->sum('delivery_price'),
            // Comissão da KeruGas (só pedidos com divisão registada).
            'commission' => (float) (clone $base)->where('status', GasOrderStatus::DELIVERED)->sum('commission'),
        ];
    }

    private function orderKpis(CarbonInterface $since, CarbonInterface $until): array
    {
        $counters = $this->orderCounters($since, $until);
        $base = $this->orders()->where('created_at', '>=', $since);

        $cancelled = (clone $base)->where('status', GasOrderStatus::CANCELLED)->count();
        $failed = (clone $base)->where('status', GasOrderStatus::FAILED)->count();
        $inProgress = $counters['total'] - $counters['delivered'] - $cancelled - $failed;

        return [
            'total' => $counters['total'],
            'delivered' => $counters['delivered'],
            'cancelled' => $cancelled,
            'failed' => $failed,
            'in_progress' => max($inProgress, 0),
            'completion_rate' => $this->percent($counters['delivered'], $counters['total']),
            'cancellation_rate' => $this->percent($cancelled, $counters['total']),
            'revenue' => $counters['revenue'],
            'commission' => $counters['commission'],
            'average_ticket' => $counters['delivered'] > 0 ? round($counters['revenue'] / $counters['delivered'], 2) : null,
            'average_minutes_to_contact' => $this->averageMinutes($since, 'contacted_at'),
            'average_minutes_to_deliver' => $this->averageMinutes($since, 'completed_at'),
        ];
    }

    /** Média em minutos entre `created_at` e `$column`, só nos pedidos que chegaram a esse passo. */
    private function averageMinutes(CarbonInterface $since, string $column): ?float
    {
        $sum = 0.0;
        $count = 0;

        $this->orders()
            ->where('created_at', '>=', $since)
            ->whereNotNull($column)
            ->select(['created_at', $column])
            ->cursor()
            ->each(function (GasOrder $order) use ($column, &$sum, &$count) {
                $minutes = $order->created_at->diffInMinutes($order->{$column}, false);
                // Dados incoerentes (relógio atrasado, edição manual) nunca
                // devem puxar a média para números negativos.
                if ($minutes >= 0) {
                    $sum += $minutes;
                    $count++;
                }
            });

        return $count > 0 ? round($sum / $count, 1) : null;
    }

    private function ordersByDay(CarbonInterface $since, int $days): array
    {
        // DB::table (não o model): o model converteria `status` num enum e a
        // comparação com o texto do estado abaixo deixaria de bater.
        $rows = DB::table('gas_orders')
            ->when($this->province !== null, fn ($q) => $q->where('province_id', $this->province))
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, status, COUNT(*) as total')
            ->groupBy('day', 'status')
            ->get();

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->format('Y-m-d');
            $ofDay = $rows->filter(fn ($row) => (string) $row->day === $date);
            $result[] = [
                'date' => $date,
                'total' => (int) $ofDay->sum('total'),
                'delivered' => (int) $ofDay->where('status', GasOrderStatus::DELIVERED->value)->sum('total'),
                'cancelled' => (int) $ofDay->where('status', GasOrderStatus::CANCELLED->value)->sum('total'),
            ];
        }

        return $result;
    }

    private function ordersByStatus(CarbonInterface $since): array
    {
        $counts = $this->orders()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Ordem do ciclo de vida, com zeros — o gráfico não "salta" passos.
        return collect(GasOrderStatus::cases())->map(fn (GasOrderStatus $status) => [
            'status' => $status->value,
            'label' => $status->label(),
            'total' => (int) ($counts[$status->value] ?? 0),
        ])->values()->all();
    }

    private function ordersByProvince(CarbonInterface $since): array
    {
        return $this->orders()
            ->join('provinces', 'provinces.id', '=', 'gas_orders.province_id')
            ->where('gas_orders.created_at', '>=', $since)
            ->selectRaw('provinces.name as name, COUNT(*) as total, SUM(CASE WHEN gas_orders.status = ? THEN 1 ELSE 0 END) as delivered', [GasOrderStatus::DELIVERED->value])
            ->groupBy('provinces.id', 'provinces.name')
            ->orderByDesc('total')
            ->orderBy('provinces.name')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'total' => (int) $row->total, 'delivered' => (int) $row->delivered])
            ->all();
    }

    /** Quantos pedidos do período chegaram a cada passo — onde se perdem clientes. */
    private function funnel(CarbonInterface $since): array
    {
        $base = $this->orders()->where('created_at', '>=', $since);

        return [
            ['key' => 'created', 'label' => 'Pedidos feitos', 'total' => (clone $base)->count()],
            ['key' => 'contacted', 'label' => 'Contactados', 'total' => (clone $base)->whereNotNull('contacted_at')->count()],
            ['key' => 'confirmed', 'label' => 'Confirmados', 'total' => (clone $base)->whereNotNull('confirmed_at')->count()],
            ['key' => 'delivered', 'label' => 'Entregues', 'total' => (clone $base)->where('status', GasOrderStatus::DELIVERED)->count()],
        ];
    }

    private function drivers(CarbonInterface $since): array
    {
        $drivers = $this->people()->where('role', UserRole::DRIVER)->where('status', 'ACTIVE');

        $perDriver = $this->orders()
            ->whereNotNull('assigned_to')
            ->where('created_at', '>=', $since)
            ->selectRaw('assigned_to, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [
                GasOrderStatus::DELIVERED->value,
                GasOrderStatus::CANCELLED->value,
            ])
            ->groupBy('assigned_to')
            ->get()
            ->keyBy('assigned_to');

        $ratings = DriverRating::query()
            ->where('created_at', '>=', $since)
            ->when($this->province !== null, fn ($q) => $q->whereIn('gas_order_id', $this->orders()->select('id')))
            ->selectRaw('driver_id, AVG(rating) as average, COUNT(*) as total')
            ->groupBy('driver_id')
            ->get()
            ->keyBy('driver_id');

        $top = User::query()
            ->whereIn('id', $perDriver->keys())
            ->get(['id', 'name'])
            ->map(function (User $driver) use ($perDriver, $ratings) {
                $row = $perDriver[$driver->id];
                $rating = $ratings[$driver->id] ?? null;

                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'delivered' => (int) $row->delivered,
                    'cancelled' => (int) $row->cancelled,
                    'average_rating' => $rating ? round((float) $rating->average, 2) : null,
                    'ratings_count' => $rating ? (int) $rating->total : 0,
                ];
            })
            ->sortBy([['delivered', 'desc'], ['name', 'asc']])
            ->take(5)
            ->values()
            ->all();

        return [
            'total' => (clone $drivers)->count(),
            'available' => (clone $drivers)->where('driver_available', true)->count(),
            'top' => $top,
        ];
    }

    private function ratings(CarbonInterface $since): array
    {
        $base = DriverRating::query()->where('created_at', '>=', $since)
            ->when($this->province !== null, fn ($q) => $q->whereIn('gas_order_id', $this->orders()->select('id')));
        $counts = (clone $base)->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $counts->sum();

        return [
            'count' => $count,
            'average' => $count > 0 ? round((float) (clone $base)->avg('rating'), 2) : null,
            'distribution' => collect(range(1, 5))->map(fn (int $stars) => [
                'stars' => $stars,
                'total' => (int) ($counts[$stars] ?? 0),
            ])->all(),
        ];
    }

    private function stations(): array
    {
        $base = Station::query()->where('status', 'ACTIVE')
            ->when($this->province !== null, fn ($q) => $q->where('province_id', $this->province));

        return [
            'active' => (clone $base)->count(),
            'with_gas' => (clone $base)->where('current_gas_available', true)->count(),
            'without_gas' => (clone $base)->where('current_gas_available', false)->count(),
            // Sem atualização há mais de 3 h (o mesmo critério do Dashboard).
            'stale' => (clone $base)->where(function ($q) {
                $q->whereNull('status_updated_at')->orWhere('status_updated_at', '<', now()->subHours(3));
            })->count(),
        ];
    }

    private function users(CarbonInterface $since): array
    {
        $byRole = $this->people()->selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role');

        return [
            'total' => (int) $byRole->sum(),
            'new_in_period' => $this->people()->where('created_at', '>=', $since)->count(),
            'by_role' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                'role' => $role->value,
                'label' => $role->label(),
                'total' => (int) ($byRole[$role->value] ?? 0),
            ])->filter(fn (array $row) => $row['total'] > 0)->values()->all(),
        ];
    }

    /** Saúde das notificações: quantas saem, quantas são lidas e quem as pode receber. */
    private function notifications(CarbonInterface $since): array
    {
        $scoped = $this->inProvinceUsers();
        $sent = $scoped(AppNotification::query()->where('created_at', '>=', $since));
        $total = (clone $sent)->count();
        $read = (clone $sent)->whereNotNull('read_at')->count();

        return [
            'sent' => $total,
            'read_rate' => $this->percent($read, $total),
            'orders_sent' => (clone $sent)->where('category', 'order')->count(),
            'web_push_subscriptions' => $scoped(WebPushSubscription::query())->count(),
            'order_push_subscriptions' => $this->province === null
                ? OrderPushSubscription::count()
                : OrderPushSubscription::query()->whereIn('gas_order_id', $this->orders()->select('id'))->count(),
            'device_tokens' => $scoped(DeviceToken::query())->count(),
        ];
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : null;
    }
}
