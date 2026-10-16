<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\GasOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Faturação por entrega (pedido explícito do cliente, Setembro 2026):
 * "quanto se fatura por dia" e "quanto cada Admin faturou".
 *
 * ÂMBITO — diferente do resto do módulo Admin (que escopa por
 * PROVÍNCIA): aqui o que decide o que cada conta vê é QUEM REGISTOU o
 * preço (`gas_orders.price_set_by`), não a província do pedido:
 *   - Um ADMIN só vê os valores que ELE PRÓPRIO registou (pedido
 *     explícito: "os admins verão simplesmente os valores que eles
 *     colocaram").
 *   - O SUPER_ADMIN vê tudo: o total de cada Admin individualmente, o
 *     somatório geral, sem filtro nenhum.
 * Isto já coincide, na prática, com o âmbito por província (um ADMIN só
 * consegue confirmar pedidos da sua própria província, logo só tem
 * `price_set_by = si próprio` em pedidos da sua província) — mas
 * filtrar explicitamente por `price_set_by` em vez de assumir isso deixa
 * claro o que está a acontecer e não quebra se a regra de província
 * alguma vez mudar.
 *
 * Só entram nos totais pedidos com `delivery_price` preenchido — ou
 * seja, pedidos já confirmados. Um pedido ainda em negociação (PENDING/
 * CONTACTING) nunca aparece aqui, propositadamente.
 */
class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $isSuperAdmin = $request->user()->role === UserRole::SUPER_ADMIN;

        $baseQuery = GasOrder::query()->whereNotNull('delivery_price');

        if (!$isSuperAdmin) {
            $baseQuery->where('price_set_by', $request->user()->id);
        }

        $today = (clone $baseQuery)->whereDate('price_set_at', today());
        $allTime = clone $baseQuery;

        $days = 14;
        $since = now()->subDays($days - 1)->startOfDay();

        $dailyRows = (clone $baseQuery)
            ->where('price_set_at', '>=', $since)
            ->selectRaw('DATE(price_set_at) as day, SUM(delivery_price) as total, COUNT(*) as count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $daily = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->format('Y-m-d');
            $row = $dailyRows->get($date);
            $daily[] = [
                'date' => $date,
                'total' => $row ? (float) $row->total : 0.0,
                'count' => $row ? (int) $row->count : 0,
            ];
        }

        $response = [
            'scope' => $isSuperAdmin ? 'all' : 'own',
            'summary' => [
                'today_total' => (float) ($today->sum('delivery_price') ?? 0),
                'today_count' => (clone $today)->count(),
                'all_time_total' => (float) ($allTime->sum('delivery_price') ?? 0),
                'all_time_count' => (clone $allTime)->count(),
            ],
            'daily' => $daily,
        ];

        if ($isSuperAdmin) {
            $response['by_admin'] = GasOrder::query()
                ->whereNotNull('delivery_price')
                ->whereNotNull('price_set_by')
                ->select('price_set_by', DB::raw('SUM(delivery_price) as total'), DB::raw('COUNT(*) as count'))
                ->groupBy('price_set_by')
                ->orderByDesc('total')
                ->get()
                ->map(function ($row) {
                    $admin = User::find($row->price_set_by);

                    return [
                        'admin_id' => $row->price_set_by,
                        'admin_name' => $admin?->name ?? 'Conta removida',
                        'province' => $admin?->province?->name,
                        'total' => (float) $row->total,
                        'count' => (int) $row->count,
                    ];
                })
                ->values();
        }

        return response()->json(['success' => true, 'data' => $response]);
    }
}
