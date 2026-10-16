<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\GasOrder;
use App\Models\SmsMessage;
use App\Models\Station;
use App\Models\StationReport;
use App\Models\User;
use App\Support\Concerns\ScopesToAdminProvince;
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

        return response()->json([
            'success' => true,
            'data' => [
                'stations' => [
                    'total' => (clone $stationsQuery)->count(),
                    'active' => (clone $stationsQuery)->where('status', 'ACTIVE')->count(),
                    'with_gas' => (clone $stationsQuery)->where('current_gas_available', true)->count(),
                    'without_gas' => (clone $stationsQuery)->where('current_gas_available', false)->count(),
                    'stale' => (clone $stationsQuery)->where('status_updated_at', '<', now()->subHours(3))->count(),
                ],
                'users' => [
                    'total' => (clone $usersQuery)->count(),
                    'active' => (clone $usersQuery)->where('status', 'ACTIVE')->count(),
                ],
                'sms' => [
                    'total' => SmsMessage::count(),
                    'processed' => SmsMessage::where('status', 'PROCESSED')->count(),
                    'invalid' => SmsMessage::where('status', 'INVALID')->count(),
                    'failed' => SmsMessage::where('status', 'FAILED')->count(),
                ],
                'reports' => [
                    'open' => (clone $reportsQuery)->where('status', 'OPEN')->count(),
                    'under_review' => (clone $reportsQuery)->where('status', 'UNDER_REVIEW')->count(),
                ],
                'gas_orders' => [
                    'today' => (clone $ordersQuery)->whereDate('created_at', today())->count(),
                    'pending' => (clone $ordersQuery)->where('status', 'PENDING')->count(),
                    'contacting' => (clone $ordersQuery)->where('status', 'CONTACTING')->count(),
                    'confirmed' => (clone $ordersQuery)->where('status', 'CONFIRMED')->count(),
                    'out_for_delivery' => (clone $ordersQuery)->where('status', 'OUT_FOR_DELIVERY')->count(),
                    'delivered' => (clone $ordersQuery)->where('status', 'DELIVERED')->count(),
                    'cancelled' => (clone $ordersQuery)->where('status', 'CANCELLED')->count(),
                ],
            ],
        ]);
    }
}
