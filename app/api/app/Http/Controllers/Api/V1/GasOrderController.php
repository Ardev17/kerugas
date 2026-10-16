<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGasOrderRequest;
use App\Http\Resources\GasOrderResource;
use App\Models\GasOrder;
use App\Services\GasOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GasOrderController extends Controller
{
    public function __construct(private readonly GasOrderService $gasOrderService)
    {
    }

    public function store(StoreGasOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()?->id;

        $order = $this->gasOrderService->create($data);

        return response()->json([
            'success' => true,
            'data' => new GasOrderResource($order),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = GasOrder::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => GasOrderResource::collection($orders),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()],
        ]);
    }

    public function showByReference(string $reference): JsonResponse
    {
        $order = GasOrder::where('order_reference', $reference)->firstOrFail();

        // Segurança do pedido (secção 197): nunca expor por adivinhação de referência
        // sem autenticação/posse. Aqui exigimos utilizador autenticado dono do pedido,
        // exceto para a página de sucesso imediatamente após a criação (tratada no frontend
        // via estado local, não nesta rota).
        $this->authorize('view', $order);

        return response()->json([
            'success' => true,
            'data' => new GasOrderResource($order->load(['province', 'municipality', 'events'])),
        ]);
    }

    public function cancel(GasOrder $gasOrder): JsonResponse
    {
        $this->authorize('cancel', $gasOrder);

        $order = $this->gasOrderService->cancel($gasOrder, request()->user());

        return response()->json(['success' => true, 'data' => new GasOrderResource($order)]);
    }
}
