<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportDriverDeliveriesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\DriverDeliveryExportService;
use App\Services\DriverStatsService;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Listagem de Condutores para o Admin/Super Admin escolher a quem
 * enviar um pedido (pedido explícito do cliente, Setembro 2026).
 *
 * ÂMBITO: um ADMIN só vê condutores DA SUA província (pode filtrar por
 * qualquer município dela, para escolher o certo); o SUPER_ADMIN vê
 * todos, com o mesmo filtro opcional. A CRIAÇÃO/gestão de contas de
 * condutor é outra coisa — estritamente SUPER_ADMIN, ver
 * UserAdminController::store()/PRIVILEGED_ROLES — este controller é só
 * de LEITURA, para o fluxo de atribuição de pedidos.
 */
class DriverAdminController extends Controller
{
    use ScopesToAdminProvince;

    public function __construct(
        private readonly DriverStatsService $driverStatsService,
        private readonly DriverDeliveryExportService $exportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->where('role', UserRole::DRIVER)->with(['province', 'municipality']);

        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        } elseif ($provinceId = $request->query('province_id')) {
            $query->where('province_id', $provinceId);
        }

        if ($municipalityId = $request->query('municipality_id')) {
            $query->where('municipality_id', $municipalityId);
        }

        if ($request->boolean('available_only')) {
            $query->where('driver_available', true);
        }

        $drivers = $query->orderBy('name')->paginate(50);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($drivers),
            'meta' => ['current_page' => $drivers->currentPage(), 'last_page' => $drivers->lastPage(), 'total' => $drivers->total()],
        ]);
    }

    /**
     * Estatísticas/ranking simples de UM condutor (pedido explícito do
     * cliente, Setembro 2026) — visível ao Admin (só da sua própria
     * província, mesmo âmbito de `index()`) e ao Super Admin (qualquer
     * condutor). Mesmo cálculo que o próprio condutor vê em
     * `DriverOrderController::stats`, via `DriverStatsService`.
     */
    public function stats(Request $request, User $driver): JsonResponse
    {
        abort_unless($driver->role === UserRole::DRIVER, 404);

        if ($this->isProvinceScopedAdmin($request)) {
            abort_unless((int) $driver->province_id === $this->adminProvinceId($request), 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->driverStatsService->forDriver($driver),
        ]);
    }

    /**
     * Exportação (CSV/PDF) de entregas por condutor (pedido explícito
     * do cliente, Setembro 2026), para pagamento/comissão. Mesmo
     * âmbito por província de `StationExportController::export` — um
     * ADMIN só exporta a SUA província, o `province_id` do pedido é
     * sempre ignorado e substituído pelo da conta.
     */
    public function export(ExportDriverDeliveriesRequest $request)
    {
        $filters = $request->validated();

        if ($this->isProvinceScopedAdmin($request)) {
            $provinceId = $this->adminProvinceId($request);

            // Mesma armadilha documentada em StationExportController:
            // `!empty($filters['province_id'])` trataria 0 como "sem
            // filtro" e devolveria dados de Angola inteira.
            abort_if($provinceId === 0, 403, 'A sua conta de administrador não tem uma província atribuída — não pode exportar dados.');

            $filters['province_id'] = $provinceId;
        }

        return $this->exportService->export($filters);
    }
}
