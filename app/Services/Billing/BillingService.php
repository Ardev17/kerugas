<?php

namespace App\Services\Billing;

use App\Enums\GasOrderStatus;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\DriverCashReceipt;
use App\Models\GasOrder;
use App\Models\Station;
use App\Models\User;
use App\Services\Stock\DriverStockService;
use App\Services\Stock\PickupService;
use App\Support\SuperAdminAudience;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Faturação da Quero Gás como INTERMEDIÁRIA (Outubro 2026).
 *
 * Regras:
 *  - PEDIDOS: só ENTREGUES contam, pelo dia da entrega (`completed_at`), no
 *    dia civil de Angola. Cada pedido = `bottle_price` (custo das garrafas)
 *    + `commission` (da Quero Gás). Cancelados não contam.
 *  - ÂMBITO dos pedidos: cada ADMIN só vê os pedidos que ELE confirmou
 *    (`price_set_by`); o SUPER ADMIN PROVINCIAL vê todos os pedidos da sua
 *    província (+ detalhe por Admin); o SUPER_ADMIN geral vê tudo.
 *  - POSTOS: o pedido não tem posto — sai do stock da KeruGás. O que se
 *    deve a cada posto vem dos CARREGAMENTOS (bottle_pickups.amount).
 *    "Pago ao posto" é binário por carregamento e fica no livro.
 *    ADMIN vê/paga os carregamentos dos condutores da sua província.
 *  - CONDUTORES: o condutor cobra o total e entrega o dinheiro no fim do
 *    dia; recibos parciais, nunca acima do em dívida; correções = estorno.
 *    ADMIN vê os condutores da sua província (dinheiro + stock).
 *  - Pedidos antigos (só total) ficam "por completar" (garrafas + divisão).
 */
class BillingService
{
    public const ACTION_STATION_PAID = 'billing.station_paid';
    public const ACTION_STATION_UNDONE = 'billing.station_payment_undone';
    public const ACTION_CASH_RECEIVED = 'billing.cash_received';
    public const ACTION_CASH_UNDONE = 'billing.cash_receipt_reversed';

    public function timezone(): string
    {
        return config('kerugas.notifications.timezone', 'Africa/Luanda');
    }

    /** Dia civil de Angola → intervalo UTC/app para consultar a BD. */
    public function dayRange(?string $date): array
    {
        $day = $date
            ? Carbon::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay()
            : Carbon::now($this->timezone())->startOfDay();

        $appTz = config('app.timezone');

        return [
            $day->copy()->setTimezone($appTz),
            $day->copy()->endOfDay()->setTimezone($appTz),
            $day->format('Y-m-d'),
        ];
    }

    public function delivered(Request $request): Builder
    {
        $q = GasOrder::query()->where('status', GasOrderStatus::DELIVERED);

        $user = $request->user();
        if ($user->isProvinceSuperAdmin()) {
            $q->where('province_id', $user->scopedProvinceId());
        } elseif (!$user->isGlobalSuperAdmin()) {
            $q->where('price_set_by', $user->id);
        }

        return $q;
    }

    public function pickups(Request $request): Builder
    {
        return app(PickupService::class)->scoped($request->user());
    }

    public function overview(Request $request, ?string $date, int $days = 14): array
    {
        [$from, $to, $dayKey] = $this->dayRange($date);

        $deliveredDay = fn () => $this->delivered($request)
            ->whereNotNull('delivery_price')
            ->whereBetween('completed_at', [$from, $to]);

        $dayRows = $deliveredDay()->whereNotNull('bottle_price')->whereNotNull('commission')
            ->get(['id', 'quantity', 'delivery_price', 'bottle_price', 'commission']);
        $legacyDay = $deliveredDay()->where(fn ($q) => $q->whereNull('bottle_price')->orWhereNull('commission'));

        $summary = [
            'orders' => $dayRows->count(),
            'bottles' => (int) $dayRows->sum('quantity'),
            'collected_total' => round((float) $dayRows->sum('delivery_price'), 2),
            'bottles_total' => round((float) $dayRows->sum('bottle_price'), 2),
            'commission_total' => round((float) $dayRows->sum('commission'), 2),
            'legacy_orders' => (clone $legacyDay)->count(),
            'legacy_total' => round((float) (clone $legacyDay)->sum('delivery_price'), 2),
        ];

        // POR POSTO (carregamentos): do dia + dívida acumulada por pagar.
        $dayPickups = $this->pickups($request)->whereBetween('registered_at', [$from, $to])
            ->selectRaw('station_id, COUNT(*) as pickups, SUM(quantity) as bottles, SUM(amount) as total')
            ->groupBy('station_id')->get()->keyBy('station_id');

        $unpaid = $this->pickups($request)->payable()->whereNull('station_paid_at')
            ->selectRaw('station_id, COUNT(*) as pickups, SUM(quantity) as bottles, SUM(amount) as total')
            ->groupBy('station_id')->get()->keyBy('station_id');

        $driverOnly = $this->pickups($request)->whereNull('station_paid_at')->whereNull('confirmed_at')
            ->selectRaw('station_id, COUNT(*) as n')->groupBy('station_id')->pluck('n', 'station_id');

        $stationIds = $dayPickups->keys()->merge($unpaid->keys())->unique()->values();
        $names = Station::withTrashed()->whereIn('id', $stationIds)->pluck('name', 'id');

        $stations = $stationIds->map(function ($id) use ($names, $dayPickups, $unpaid, $driverOnly) {
            $d = $dayPickups->get($id);
            $u = $unpaid->get($id);

            return [
                'station_id' => (int) $id,
                'station_name' => $names[$id] ?? 'Posto removido',
                'day_pickups' => $d ? (int) $d->pickups : 0,
                'day_bottles' => $d ? (int) $d->bottles : 0,
                'day_total' => $d ? round((float) $d->total, 2) : 0.0,
                'unpaid_pickups' => $u ? (int) $u->pickups : 0,
                'unpaid_bottles' => $u ? (int) $u->bottles : 0,
                'unpaid_total' => $u ? round((float) $u->total, 2) : 0.0,
                'unconfirmed_pickups' => (int) ($driverOnly[$id] ?? 0),
            ];
        })->sortByDesc('unpaid_total')->values();

        $summary['stations_day_total'] = round((float) $stations->sum('day_total'), 2);
        $summary['stations_unpaid_total'] = round((float) $stations->sum('unpaid_total'), 2);

        $response = [
            'date' => $dayKey,
            'summary' => $summary,
            'stations' => $stations,
            'daily' => $this->daily($request, $dayKey, $days),
            'legacy' => $this->legacyList($request),
            'drivers' => $this->drivers($request, $from, $to),
        ];

        if ($request->user()->hasSuperPowers()) {
            $response['by_admin'] = $this->byAdmin($from, $to, $request->user()->scopedProvinceId());
        }

        return $response;
    }

    private function daily(Request $request, string $endDay, int $days): array
    {
        $end = Carbon::createFromFormat('Y-m-d', $endDay, $this->timezone())->startOfDay();
        $start = $end->copy()->subDays($days - 1);
        $appTz = config('app.timezone');

        $rows = $this->delivered($request)
            ->whereNotNull('delivery_price')
            ->whereBetween('completed_at', [$start->copy()->setTimezone($appTz), $end->copy()->endOfDay()->setTimezone($appTz)])
            ->get(['completed_at', 'delivery_price', 'bottle_price', 'commission']);

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[$start->copy()->addDays($i)->format('Y-m-d')] = ['collected' => 0.0, 'bottles' => 0.0, 'commission' => 0.0, 'count' => 0];
        }

        foreach ($rows as $row) {
            $key = $row->completed_at->copy()->setTimezone($this->timezone())->format('Y-m-d');
            if (!isset($out[$key])) {
                continue;
            }
            $out[$key]['count']++;
            $out[$key]['collected'] += (float) $row->delivery_price;
            if ($row->bottle_price !== null && $row->commission !== null) {
                $out[$key]['bottles'] += (float) $row->bottle_price;
                $out[$key]['commission'] += (float) $row->commission;
            }
        }

        return collect($out)->map(fn ($v, $k) => [
            'date' => $k,
            'count' => $v['count'],
            'collected' => round($v['collected'], 2),
            'bottles' => round($v['bottles'], 2),
            'commission' => round($v['commission'], 2),
        ])->values()->all();
    }

    /** Pedidos antigos entregues, ainda sem divisão. */
    private function legacyList(Request $request): array
    {
        $q = $this->delivered($request)->whereNotNull('delivery_price')
            ->where(fn ($q) => $q->whereNull('bottle_price')->orWhereNull('commission'));

        return [
            'count' => (clone $q)->count(),
            'items' => (clone $q)->orderBy('completed_at')->limit(50)
                ->get(['id', 'order_reference', 'quantity', 'delivery_price', 'completed_at', 'customer_name'])
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_reference' => $o->order_reference,
                    'customer_name' => $o->customer_name,
                    'quantity' => (int) $o->quantity,
                    'delivery_price' => (float) $o->delivery_price,
                    'completed_at' => $o->completed_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /** Dinheiro por entregar de cada condutor (cobrado − recibido). */
    public function drivers(Request $request, CarbonInterface $from, CarbonInterface $to): array
    {
        $user = $request->user();

        $drivers = User::query()->where('role', UserRole::DRIVER)
            ->when($user->scopedProvinceId() !== null, fn ($q) => $q->where('province_id', $user->scopedProvinceId()))
            ->get(['id', 'name', 'phone', 'province_id']);

        if ($drivers->isEmpty()) {
            return [];
        }

        $ids = $drivers->pluck('id');

        $collected = GasOrder::query()->where('status', GasOrderStatus::DELIVERED)->whereNotNull('delivery_price')
            ->whereIn('assigned_to', $ids)->selectRaw('assigned_to, SUM(delivery_price) as total, COUNT(*) as orders')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $collectedDay = GasOrder::query()->where('status', GasOrderStatus::DELIVERED)->whereNotNull('delivery_price')
            ->whereIn('assigned_to', $ids)->whereBetween('completed_at', [$from, $to])
            ->selectRaw('assigned_to, SUM(delivery_price) as total')->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $received = DriverCashReceipt::query()->whereIn('driver_id', $ids)
            ->selectRaw('driver_id, SUM(amount) as total')->groupBy('driver_id')->get()->keyBy('driver_id');

        $stock = app(DriverStockService::class)->forDrivers($ids);

        return $drivers->map(function (User $d) use ($collected, $collectedDay, $received, $stock) {
            $c = round((float) ($collected[$d->id]->total ?? 0), 2);
            $r = round((float) ($received[$d->id]->total ?? 0), 2);

            return [
                'driver_id' => $d->id,
                'driver_name' => $d->name,
                'phone' => $d->phone,
                'orders' => (int) ($collected[$d->id]->orders ?? 0),
                'collected_day' => round((float) ($collectedDay[$d->id]->total ?? 0), 2),
                'collected_total' => $c,
                'received_total' => $r,
                'outstanding' => round($c - $r, 2),
                'stock' => $stock[$d->id],
            ];
        })->filter(fn ($row) => $row['collected_total'] > 0 || $row['received_total'] != 0 || $row['stock']['picked'] > 0)
            ->sortByDesc('outstanding')->values()->all();
    }

    private function byAdmin(CarbonInterface $from, CarbonInterface $to, ?int $provinceId = null): array
    {
        return GasOrder::query()->where('status', GasOrderStatus::DELIVERED)
            ->when($provinceId !== null, fn ($q) => $q->where('province_id', $provinceId))
            ->whereNotNull('price_set_by')->whereNotNull('delivery_price')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('price_set_by, SUM(delivery_price) as total, SUM(commission) as commission, COUNT(*) as count')
            ->groupBy('price_set_by')->orderByDesc('total')->get()
            ->map(function ($row) {
                $admin = User::find($row->price_set_by);

                return [
                    'admin_id' => $row->price_set_by,
                    'admin_name' => $admin?->name ?? 'Conta removida',
                    'province' => $admin?->province?->name,
                    'total' => (float) $row->total,
                    'commission' => (float) $row->commission,
                    'count' => (int) $row->count,
                ];
            })->values()->all();
    }

    /**
     * Marca como PAGOS ao posto todos os carregamentos ainda por pagar
     * (no âmbito de quem paga) — opcionalmente só até ao fim do dia `$until`.
     * Tudo ou nada; um registo no livro com a lista de carregamentos.
     */
    public function payStation(Request $request, Station $station, ?string $until = null): array
    {
        $actor = $request->user();

        $result = DB::transaction(function () use ($request, $station, $until, $actor) {
            $q = $this->pickups($request)->payable()->where('station_id', $station->id)
                ->whereNull('station_paid_at')->lockForUpdate();

            if ($until) {
                [, $to] = $this->dayRange($until);
                $q->where('registered_at', '<=', $to);
            }

            $rows = $q->get(['id', 'amount', 'quantity']);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['station' => 'Não há nada por pagar a este posto.']);
            }

            $amount = round((float) $rows->sum('amount'), 2);

            BottlePickup::whereIn('id', $rows->pluck('id'))->update([
                'station_paid_at' => now(),
                'station_paid_by' => $actor->id,
            ]);

            $log = $this->ledger($request, self::ACTION_STATION_PAID, Station::class, $station->id, [], [
                'amount' => $amount,
                'pickups' => $rows->count(),
                'bottles' => (int) $rows->sum('quantity'),
                'pickup_ids' => $rows->pluck('id')->all(),
                'until' => $until,
                'station_name' => $station->name,
                // Província do registo (filtra o livro do Super Admin Provincial).
                'province_id' => $actor->scopedProvinceId() ?: $station->province_id,
            ]);

            return ['amount' => $amount, 'pickups' => $rows->count(), 'log_id' => $log->id];
        });

        $this->notifySupers($actor, $actor->scopedProvinceId() ?: $station->province_id, 'Pagamento a posto registado',
            "{$actor->name} marcou como pago ao posto {$station->name}: " . $this->kz($result['amount']) . " ({$result['pickups']} carregamentos).");

        return $result;
    }

    /** Recebimento de dinheiro de um condutor (parcial permitido, nunca acima do em dívida). */
    public function receiveCash(Request $request, User $driver, float $amount, ?string $note): array
    {
        $actor = $request->user();

        if (!$actor->canAccessProvince($driver->province_id)) {
            abort(403, 'Este condutor pertence a outra província.');
        }

        abort_unless($driver->role === UserRole::DRIVER, 422, 'O utilizador escolhido não é um condutor.');

        $result = DB::transaction(function () use ($request, $driver, $amount, $note, $actor) {
            // Bloqueia o condutor para serializar recebimentos concorrentes.
            User::whereKey($driver->id)->lockForUpdate()->first();

            $collected = (float) GasOrder::where('status', GasOrderStatus::DELIVERED)
                ->where('assigned_to', $driver->id)->sum('delivery_price');
            $received = (float) DriverCashReceipt::where('driver_id', $driver->id)->sum('amount');
            $outstanding = round($collected - $received, 2);

            if ($amount - $outstanding > 0.009) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor excede o que o condutor tem por entregar (' . $this->kz($outstanding) . ').',
                ]);
            }

            $receipt = DriverCashReceipt::create([
                'driver_id' => $driver->id,
                'received_by' => $actor->id,
                'amount' => round($amount, 2),
                'note' => $note,
                'received_at' => now(),
            ]);

            $this->ledger($request, self::ACTION_CASH_RECEIVED, DriverCashReceipt::class, $receipt->id, [], [
                'driver_id' => $driver->id,
                'driver_name' => $driver->name,
                'amount' => round($amount, 2),
                'outstanding_after' => round($outstanding - $amount, 2),
                'province_id' => $driver->province_id,
            ]);

            return ['receipt_id' => $receipt->id, 'amount' => round($amount, 2), 'outstanding' => round($outstanding - $amount, 2)];
        });

        $this->notifyUser($driver->id, 'Dinheiro recebido',
            "{$actor->name} registou o recebimento de " . $this->kz($result['amount']) . '. Em falta: ' . $this->kz($result['outstanding']) . '.',
            '/driver');
        $this->notifySupers($actor, $driver->province_id, 'Dinheiro de condutor recebido',
            "{$actor->name} recebeu " . $this->kz($result['amount']) . " de {$driver->name}. Em falta: " . $this->kz($result['outstanding']) . '.');

        return $result;
    }

    /** Livro: ações financeiras com o estado "desfeito" calculado. */
    public function ledger(Request $request, string $action, string $entityType, int $entityId, array $old, array $new): AuditLog
    {
        return AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    public function ledgerList(?int $provinceId = null, int $limit = 100): array
    {
        $actions = [self::ACTION_STATION_PAID, self::ACTION_STATION_UNDONE, self::ACTION_CASH_RECEIVED, self::ACTION_CASH_UNDONE];

        // Super Admin Provincial: só os registos da sua província (JSON no SQL —
        // `json_extract` em MySQL e SQLite).
        $logs = AuditLog::with('user:id,name')->whereIn('action', $actions)
            ->when($provinceId !== null, fn ($q) => $q->where('new_values->province_id', $provinceId))
            ->latest('id')->limit($limit)->get();

        $undone = AuditLog::whereIn('action', [self::ACTION_STATION_UNDONE, self::ACTION_CASH_UNDONE])->get()
            ->map(fn ($l) => $l->new_values['undoes_log_id'] ?? null)->filter()->all();

        return $logs->map(fn (AuditLog $l) => [
            'id' => $l->id,
            'action' => $l->action,
            'by' => $l->user?->name ?? 'Conta removida',
            'at' => $l->created_at?->toIso8601String(),
            'details' => $l->new_values,
            'undone' => in_array($l->id, $undone, true),
            'can_undo' => in_array($l->action, [self::ACTION_STATION_PAID, self::ACTION_CASH_RECEIVED], true) && !in_array($l->id, $undone, true),
        ])->values()->all();
    }

    /** Só Super Admin (verificado no controller). */
    public function undo(Request $request, AuditLog $log): array
    {
        $scope = $request->user()->scopedProvinceId();
        if ($scope !== null && (int) ($log->new_values['province_id'] ?? 0) !== $scope) {
            abort(403, 'Este registo pertence a outra província.');
        }

        return DB::transaction(function () use ($request, $log) {
            $already = AuditLog::whereIn('action', [self::ACTION_STATION_UNDONE, self::ACTION_CASH_UNDONE])
                ->get()->contains(fn ($l) => ($l->new_values['undoes_log_id'] ?? null) === $log->id);

            if ($already) {
                throw ValidationException::withMessages(['log' => 'Este registo já foi desfeito.']);
            }

            if ($log->action === self::ACTION_STATION_PAID) {
                $ids = $log->new_values['pickup_ids'] ?? [];
                $n = BottlePickup::whereIn('id', $ids)->whereNotNull('station_paid_at')->update(['station_paid_at' => null, 'station_paid_by' => null]);
                $this->ledger($request, self::ACTION_STATION_UNDONE, Station::class, $log->entity_id, [], [
                    'undoes_log_id' => $log->id, 'pickups' => $n, 'amount' => $log->new_values['amount'] ?? 0,
                    'province_id' => $log->new_values['province_id'] ?? null,
                    'station_name' => $log->new_values['station_name'] ?? null,
                ]);

                return ['undone' => 'station_payment', 'pickups' => $n];
            }

            if ($log->action === self::ACTION_CASH_RECEIVED) {
                $orig = DriverCashReceipt::findOrFail($log->entity_id);
                $rev = DriverCashReceipt::create([
                    'driver_id' => $orig->driver_id,
                    'received_by' => $request->user()->id,
                    'amount' => -1 * (float) $orig->amount,
                    'note' => "Estorno do recibo #{$orig->id}",
                    'received_at' => now(),
                ]);
                $this->ledger($request, self::ACTION_CASH_UNDONE, DriverCashReceipt::class, $rev->id, [], [
                    'undoes_log_id' => $log->id, 'amount' => (float) $orig->amount,
                    'driver_id' => $orig->driver_id, 'driver_name' => $log->new_values['driver_name'] ?? null,
                    'province_id' => $log->new_values['province_id'] ?? null,
                ]);

                return ['undone' => 'cash_receipt', 'receipt_id' => $rev->id];
            }

            throw ValidationException::withMessages(['log' => 'Este tipo de registo não pode ser desfeito.']);
        });
    }

    private function kz(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' Kz';
    }

    /**
     * Avisa os Super Admins gerais e os Super Admins Provinciais da província
     * da ação (Outubro 2026) — nunca quem fez a ação. Melhor esforço.
     */
    private function notifySupers(User $actor, ?int $provinceId, string $title, string $body): void
    {
        try {
            SuperAdminAudience::ids($provinceId, $actor->id)
                ->each(fn ($id) => $this->notifyUser($id, $title, $body, '/admin/billing'));
        } catch (Throwable) {
            // não crítico
        }
    }

    private function notifyUser(int $userId, string $title, string $body, string $path): void
    {
        try {
            AppNotification::create([
                'user_id' => $userId,
                'category' => NotificationCategory::SYSTEM,
                'title' => $title,
                'body' => $body,
                'data' => ['type' => 'billing', 'path' => $path],
            ]);
        } catch (Throwable) {
            // não crítico
        }
    }
}
