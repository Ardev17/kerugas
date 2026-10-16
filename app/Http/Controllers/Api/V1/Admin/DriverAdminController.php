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
use App\Support\LocationGroups;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Organização por categoria → província → município (Outubro 2026):
     * contagens para as abas e para as secções do painel. Mesma consulta e
     * mesmo âmbito da lista.
     */
    public function groups(Request $request): JsonResponse
    {
        // As contagens das abas ignoram o filtro "disponíveis" (senão "Todos"
        // mostraria o mesmo número que "Disponíveis").
        $all = $this->listQuery($request, withAvailability: false);

        return response()->json(['success' => true, 'data' => [
            'tabs' => [
                ['key' => 'all', 'count' => (clone $all)->count()],
                ['key' => 'available', 'count' => (clone $all)->where('driver_available', true)->count()],
            ],
            'groups' => LocationGroups::byColumns($this->listQuery($request)),
        ]]);
    }

    private function listQuery(Request $request, bool $withAvailability = true): Builder
    {
        $query = User::query()->where('role', UserRole::DRIVER);

        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        }

        if ($withAvailability && $request->boolean('available_only')) {
            $query->where('driver_available', true);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery($request)->with(['province', 'municipality']);
        LocationGroups::filterColumns($query, $request);

        $drivers = $query->orderBy('name')->orderBy('id')->paginate(50);

        // Stock de garrafas (Outubro 2026): visível na escolha do condutor.
        $stock = app(\App\Services\Stock\DriverStockService::class)->forDrivers($drivers->getCollection()->pluck('id'));
        $data = UserResource::collection($drivers)->resolve($request);
        foreach ($data as $i => $row) {
            $data[$i]['bottle_stock'] = $stock[$row['id']] ?? null;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
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
