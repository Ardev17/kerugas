<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGasOrderRequest;
use App\Http\Resources\GasOrderResource;
use App\Models\GasOrder;
use App\Services\PhoneNumberService;
use Illuminate\Support\Arr;
use App\Services\GasOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GasOrderController extends Controller
{
    public function __construct(
        private readonly GasOrderService $gasOrderService,
        private readonly PhoneNumberService $phoneNumberService,
    ) {
    }

    public function store(StoreGasOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        // BUG CORRIGIDO (Setembro 2026): `POST /orders` é pública (um
        // visitante sem conta pode pedir gás), por isso não passa pelo
        // middleware `auth:sanctum` — e sem ele, `$request->user()` usa o
        // guard por omissão (`web`, sessão por cookie) e devolve SEMPRE
        // null para quem se autentica por Bearer token. Resultado: os
        // pedidos de clientes com sessão iniciada ficavam gravados sem
        // `user_id` — "os meus pedidos" aparecia sempre vazio e o cliente
        // nunca recebia notificações do próprio pedido. Pedir o guard
        // `sanctum` explicitamente lê o token quando existe, e continua a
        // devolver null para visitantes.
        $data['user_id'] = $request->user('sanctum')?->id;

        $order = $this->gasOrderService->create($data);

        return response()->json([
            'success' => true,
            'data' => new GasOrderResource($order),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        // Filtro de "Os meus pedidos" (Outubro 2026): em curso, entregues ou
        // cancelados (cancelados inclui os que falharam). Sem filtro, todos.
        $filter = $request->validate(['filter' => ['nullable', 'in:active,delivered,cancelled']])['filter'] ?? null;

        $orders = GasOrder::query()
            ->where('user_id', $request->user()->id)
            ->when($filter === 'active', fn ($q) => $q->whereNotIn('status', ['DELIVERED', 'CANCELLED', 'FAILED']))
            ->when($filter === 'delivered', fn ($q) => $q->where('status', 'DELIVERED'))
            ->when($filter === 'cancelled', fn ($q) => $q->whereIn('status', ['CANCELLED', 'FAILED']))
            ->with(['assignedUser', 'rating'])
            ->latest()
            ->latest('id')
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
            'data' => new GasOrderResource($order->load(['province', 'municipality', 'events.user:id,name', 'assignedUser', 'rating'])),
        ]);
    }

    /**
     * Acompanhamento SEM conta (Outubro 2026) — a maioria dos clientes pede
     * gás sem criar conta, e o botão "Acompanhar pedido" tem de funcionar
     * para eles. Prova de posse: referência + telefone do pedido (o mesmo
     * critério da avaliação do condutor), com `throttle:ratings` contra
     * tentativas de adivinhar o telefone.
     *
     * Devolve só o necessário para a linha de progresso: nunca o telefone,
     * notas, coordenadas, histórico interno nem dados de faturação.
     */
    public function trackByPhone(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['customer_phone' => ['required', 'string', 'max:30']]);

        $order = GasOrder::where('order_reference', $reference)->firstOrFail();

        $input = $this->phoneNumberService->normalize($data['customer_phone']);
        $stored = $this->phoneNumberService->normalize($order->customer_phone);

        // 404 igual ao de "referência inexistente": não confirma que a referência existe.
        abort_if($input === null || $stored === null || $input !== $stored, 404);

        $order->load(['province', 'municipality', 'assignedUser', 'rating']);

        return response()->json([
            'success' => true,
            'data' => Arr::only((new GasOrderResource($order))->resolve($request), [
                'reference', 'status', 'status_label', 'province', 'municipality', 'neighborhood',
                'address', 'quantity', 'cylinder_type', 'cylinder_types_confirmed',
                'assigned_to', 'assigned_to_avatar_url', 'created_at', 'completed_at', 'can_rate', 'driver_rating',
            ]),
        ]);
    }

    public function cancel(GasOrder $gasOrder): JsonResponse
    {
        $this->authorize('cancel', $gasOrder);

        $order = $this->gasOrderService->cancel($gasOrder, request()->user());

        return response()->json(['success' => true, 'data' => new GasOrderResource($order)]);
    }
}
