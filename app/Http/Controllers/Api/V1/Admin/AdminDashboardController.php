<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\GasOrder;
use App\Models\SmsMessage;
use App\Models\Station;
use App\Models\StationReport;
use App\Models\User;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KPIs do dashboard admin (secção 36 e 233). Consultas simples e
 * diretas — se o volume crescer, mover para tabelas agregadas
 * pré-calculadas (analytics_events já existe para esse fim).
 *
 * ÂMBITO POR PROVÍNCIA: os blocos "stations", "gas_orders", "reports"
 * e "users" ficam filtrados pela província do ADMIN — os mesmos
 * módulos restringidos em `StationAdminController`/
 * `AdminOrderController`/`ReportAdminController`/`UserAdminController`.
 * Sem isto, um ADMIN impedido de ver postos, denúncias ou utilizadores
 * de outra província no respetivo separador continuaria a ver a
 * contagem nacional aqui — uma fuga de informação disfarçada de "só é
 * um número" (e, no caso de "users", um número que nem sequer bateria
 * certo com o que o próprio separador de Utilizadores mostra). "sms"
 * fica sempre nacional — pedido explícito do cliente (Central de
 * Operações mantém-se sem restrição).
 */
class AdminDashboardController extends Controller
{
    use ScopesToAdminProvince;

    public function index(Request $request): JsonResponse
    {
        $scoped = $this->isProvinceScopedAdmin($request);
        $provinceId = $this->adminProvinceId($request);

        $stationsQuery = Station::query();
        $ordersQuery = GasOrder::query();
        $reportsQuery = StationReport::query();
        $usersQuery = User::query();

        if ($scoped) {
            $stationsQuery->where('province_id', $provinceId);
            $ordersQuery->where('province_id', $provinceId);
            $reportsQuery->whereHas('station', fn ($q) => $q->where('province_id', $provinceId));
            $usersQuery->where('province_id', $provinceId);
        }

        // Velocidade (Outubro 2026): uma contagem agrupada por tabela em vez
        // de um COUNT por número (eram 19 consultas a cada atualização do
        // painel; agora são 7). Os números são exatamente os mesmos.
        $stationStatus = $this->countBy($stationsQuery, 'status');
        $stationGas = $this->countBy($stationsQuery, 'current_gas_available');
        $userStatus = $this->countBy($usersQuery, 'status');
        $smsStatus = $this->countBy(SmsMessage::query(), 'status');
        $reportStatus = $this->countBy($reportsQuery, 'status');
        $orderStatus = $this->countBy($ordersQuery, 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'stations' => [
                    'total' => array_sum($stationStatus),
                    'active' => $stationStatus['ACTIVE'] ?? 0,
                    'with_gas' => $stationGas[1] ?? 0,
                    'without_gas' => $stationGas[0] ?? 0,
                    'stale' => (clone $stationsQuery)->where('status_updated_at', '<', now()->subHours(3))->count(),
                ],
                'users' => [
                    'total' => array_sum($userStatus),
                    'active' => $userStatus['ACTIVE'] ?? 0,
                ],
                'sms' => [
                    'total' => array_sum($smsStatus),
                    'processed' => $smsStatus['PROCESSED'] ?? 0,
                    'invalid' => $smsStatus['INVALID'] ?? 0,
                    'failed' => $smsStatus['FAILED'] ?? 0,
                ],
                'reports' => [
                    'open' => $reportStatus['OPEN'] ?? 0,
                    'under_review' => $reportStatus['UNDER_REVIEW'] ?? 0,
                ],
                'gas_orders' => [
                    // Intervalo em vez de whereDate(): assim a base de dados usa o índice de created_at.
                    'today' => (clone $ordersQuery)
                        ->where('created_at', '>=', today())
                        ->where('created_at', '<', today()->addDay())
                        ->count(),
                    'pending' => $orderStatus['PENDING'] ?? 0,
                    'contacting' => $orderStatus['CONTACTING'] ?? 0,
                    'confirmed' => $orderStatus['CONFIRMED'] ?? 0,
                    'out_for_delivery' => $orderStatus['OUT_FOR_DELIVERY'] ?? 0,
                    'delivered' => $orderStatus['DELIVERED'] ?? 0,
                    'cancelled' => $orderStatus['CANCELLED'] ?? 0,
                ],
            ],
        ]);
    }

    /**
     * Quantas linhas há para cada valor de uma coluna, numa só consulta.
     * Valores booleanos chegam como 0/1 (MySQL e SQLite).
     *
     * @return array<int|string, int>
     */
    private function countBy(Builder $query, string $column): array
    {
        $counts = [];
        $rows = (clone $query)->toBase()
            ->select($column)
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            $key = $row->{$column};
            $key = is_bool($key) ? (int) $key : ($key ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->aggregate;
        }

        return $counts;
    }
}
