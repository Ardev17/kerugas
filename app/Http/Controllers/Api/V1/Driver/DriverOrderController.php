<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\GasOrderResource;
use App\Http\Resources\UserResource;
use App\Models\GasOrder;
use App\Services\DriverStatsService;
use App\Services\GasOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard do Condutor (pedido explícito do cliente, Setembro 2026).
 * Segue o mesmo padrão de OperatorStationController: um papel
 * operacional restrito ao seu próprio âmbito (aqui, os pedidos que LHE
 * foram atribuídos), sem depender do mapa de permissões granulares —
 * a verificação "é mesmo um condutor" e "este pedido é mesmo dele" é
 * feita diretamente no controller, reforçada por `Route::middleware`
 * em routes/api.php restringir todo o prefixo `/driver` a
 * `role:DRIVER`.
 *
 * FLUXO DIRETO (pedido explícito do cliente): um pedido atribuído a um
 * condutor vai de ASSIGNED diretamente para DELIVERED ("Feita") ou
 * CANCELLED ("Cancelado") — nunca passa por OUT_FOR_DELIVERY aqui (esse
 * estado intermédio só existe no fluxo administrativo antigo).
 *
 * RESTRIÇÃO DE PAPEL: concluir/cancelar só é possível se
 * `assigned_to === auth()->id()` — nunca outro condutor, mesmo que veja
 * a lista de pedidos do seu município no chat. O Super Admin usa a via
 * `AdminOrderController` para o mesmo efeito, não esta.
 */
class DriverOrderController extends Controller
{
    public function __construct(
        private readonly GasOrderService $gasOrderService,
        private readonly DriverStatsService $driverStatsService,
    ) {
    }

    /**
     * Estatísticas simples do próprio condutor (pedido explícito do
     * cliente, Setembro 2026) — mesmo cálculo usado pelo Admin/Super
     * Admin em `DriverAdminController::stats`, ver `DriverStatsService`.
     */
    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->driverStatsService->forDriver($request->user()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = GasOrder::query()
            ->where('assigned_to', $request->user()->id)
            ->with(['province', 'municipality', 'assignedUser']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => GasOrderResource::collection($orders),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]);
    }

    public function complete(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertOwnOrder($request, $order);

        $updated = $this->gasOrderService->complete($order, $request->user());

        return response()->json(['success' => true, 'data' => new GasOrderResource($updated)]);
    }

    public function cancel(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertOwnOrder($request, $order);

        $updated = $this->gasOrderService->cancel($order, $request->user(), $request->input('reason'));

        return response()->json(['success' => true, 'data' => new GasOrderResource($updated)]);
    }

    /**
     * Alternância manual de disponibilidade (pedido explícito do
     * cliente, "automático + manual") — soma-se, nunca substitui, à
     * alternância automática já feita por GasOrderService::assign()/
     * complete()/cancel(). Um condutor pode marcar-se indisponível a
     * qualquer momento (ex: fim de turno), mesmo sem entrega em curso.
     */
    public function toggleAvailability(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['driver_available' => !$user->driver_available]);

        return response()->json(['success' => true, 'data' => new UserResource($user->fresh())]);
    }

    private function assertOwnOrder(Request $request, GasOrder $order): void
    {
        abort_unless($request->user()->role === UserRole::DRIVER, 403);
        abort_unless((int) $order->assigned_to === (int) $request->user()->id, 403, 'Este pedido não está atribuído a si.');
    }
}
