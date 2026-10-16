<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\GasOrderResource;
use App\Models\AuditLog;
use App\Models\GasOrder;
use App\Models\User;
use App\Services\GasOrderService;
use App\Services\Stock\DriverStockService;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use App\Support\LocationGroups;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gestão administrativa de pedidos de gás (secção 183-188, 218).
 * Delega toda a transição de estado ao GasOrderService — este
 * controller apenas valida entrada e chama o serviço.
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só vê e
 * só consegue agir sobre pedidos da SUA província — a mesma atribuída
 * à conta dele em `users.province_id`. Só o SUPER_ADMIN continua a ver
 * pedidos de todas as províncias, sem filtro nenhum. Isto aplica-se em
 * dois sítios, e os dois são necessários:
 *   1. `index()` — filtra a query, para a listagem já vir escopada.
 *   2. `assertProvinceAccess()` — chamado em CADA ação individual
 *      (show/contact/confirm/assign/delivery/complete/cancel), porque
 *      o route model binding do Laravel resolve `{order}` diretamente
 *      pelo ID, ignorando por completo o filtro de `index()`. Sem este
 *      segundo bloqueio, um ADMIN da província de Benguela conseguiria
 *      agir sobre um pedido do Huambo só por adivinhar/tentar o ID na
 *      URL — o filtro da listagem sozinho é só cosmético.
 *
 * MODERATOR mantém-se sem filtro (fora do pedido original, que falou
 * apenas de "administrador" vs "super administrador") — revisitar se o
 * mesmo âmbito regional fizer sentido também para moderadores.
 */
class AdminOrderController extends Controller
{
    use ScopesToAdminProvince;

    public function __construct(private readonly GasOrderService $gasOrderService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery($request, withStatus: true)
            ->with(['province', 'municipality', 'assignedUser', 'priceSetBy', 'priceCorrectedBy']);
        LocationGroups::filterColumns($query, $request);

        $orders = $query->latest()->latest('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => GasOrderResource::collection($orders),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]);
    }

    public function show(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        return response()->json([
            'success' => true,
            'data' => new GasOrderResource($order->load(['province', 'municipality', 'assignedUser', 'priceSetBy', 'priceCorrectedBy', 'events.user'])),
        ]);
    }

    public function contact(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        return $this->respondWith($this->gasOrderService->markContacted($order, $request->user()));
    }

    /**
     * Faturação por entrega (pedido explícito do cliente, Setembro
     * 2026): o preço é obrigatório neste passo — só se confirma um
     * pedido depois de o Admin e o cliente terem acertado o valor por
     * telefone. `min:0` em vez de `min:0.01` porque uma entrega
     * gratuita/cortesia é um caso de negócio válido, não um erro.
     */
    /**
     * Confirmação do preço E do(s) tipo(s) de garrafa confirmados
     * (pedido explícito do cliente, Setembro 2026) — os dois passos
     * fundem-se num só pedido, porque o Admin confirma ambos com o
     * cliente na mesma chamada telefónica. `cylinder_types_confirmed`
     * é sempre obrigatório aqui (distinto de `cylinder_type`, que o
     * cliente pode ter deixado em branco no pedido inicial).
     */
    public function confirm(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        // KeruGas INTERMEDIÁRIA (Outubro 2026): o Admin introduz DOIS
        // valores — o das garrafas (total do pedido) e a comissão (da KeruGas
        //, negociada caso a caso, nunca fixa). O total que o cliente paga
        // é a soma, calculada aqui. Sem posto: o pedido sai do stock que o
        // condutor carregou (o posto fica no carregamento). `quantity` só sobe.
        $data = $request->validate([
            'bottle_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'commission' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'quantity' => ['nullable', 'integer', 'min:' . max(1, (int) $order->quantity), 'max:100'],
            'cylinder_types_confirmed' => ['required', 'string', 'max:255'],
        ], [
            'quantity.min' => 'O número de garrafas não pode diminuir (mínimo: ' . max(1, (int) $order->quantity) . ').',
        ]);

        $total = round((float) $data['bottle_price'] + (float) $data['commission'], 2);

        return $this->respondWith($this->gasOrderService->confirm(
            $order,
            $total,
            $data['cylinder_types_confirmed'],
            $request->user(),
            [
                'bottle_price' => (float) $data['bottle_price'],
                'commission' => (float) $data['commission'],
                'quantity' => $data['quantity'] ?? null,
            ]
        ));
    }

    /**
     * Completa um pedido ANTIGO (só tinha o total) com a divisão nova:
     * garrafas + valor das garrafas + comissão (sem posto). A soma tem de
     * igualar o total que o cliente já pagou/viu — nunca se altera aqui o
     * total, só se explica como ele se divide. Fica no AuditLog.
     */
    public function completeBilling(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        abort_if($order->delivery_price === null, 422, 'Este pedido ainda não tem preço — confirme-o primeiro.');
        abort_if($order->hasBillingSplit(), 422, 'Este pedido já tem a divisão registada. Só o Super Admin a pode corrigir (alterar preço).');

        $data = $request->validate([
            'bottle_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'commission' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'quantity' => ['required', 'integer', 'min:' . max(1, (int) $order->quantity), 'max:100'],
        ], [
            'quantity.min' => 'O número de garrafas não pode diminuir (mínimo: ' . max(1, (int) $order->quantity) . ').',
        ]);

        $sum = round((float) $data['bottle_price'] + (float) $data['commission'], 2);

        if (abs($sum - (float) $order->delivery_price) > 0.009) {
            throw ValidationException::withMessages([
                'bottle_price' => 'O valor das garrafas + a comissão (' . number_format($sum, 2, ',', '.') . ' Kz) tem de ser igual ao total já registado (' . number_format((float) $order->delivery_price, 2, ',', '.') . ' Kz).',
            ]);
        }

        $old = ['quantity' => (int) $order->quantity];

        $order->update([
            'bottle_price' => (float) $data['bottle_price'],
            'commission' => (float) $data['commission'],
            'quantity' => (int) $data['quantity'],
        ]);

        $this->audit($request, 'order.billing_completed', $order, $old, [
            'bottle_price' => (float) $data['bottle_price'],
            'commission' => (float) $data['commission'],
            'quantity' => (int) $data['quantity'],
        ]);

        return $this->respondWith($order->fresh());
    }

    /**
     * Atribuição genérica (mantida para compatibilidade — qualquer
     * utilizador elegível, sem restrição de município). Para atribuir
     * especificamente a um Condutor, usar `assignToDriver()`, que
     * impõe a regra "só recebe pedidos do seu próprio município".
     */
    public function assign(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        $request->validate(['assignee_id' => ['required', 'exists:users,id']]);

        $assignee = User::findOrFail($request->input('assignee_id'));

        return $this->respondWith($this->gasOrderService->assign($order, $assignee, $request->user()));
    }

    /**
     * Envio do pedido a um Condutor (pedido explícito do cliente,
     * Setembro 2026): só depois de finalizado (preço + garrafas
     * confirmados), o Admin/Super Admin escolhe um condutor
     * DISPONÍVEL do MESMO município do pedido — nunca de outro
     * município, mesmo que esteja livre. `DriverAdminController::index`
     * é quem lista/filtra os condutores elegíveis para esta escolha.
     */
    public function assignToDriver(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        $request->validate(['driver_id' => ['required', 'exists:users,id']]);

        $driver = User::findOrFail($request->input('driver_id'));

        abort_unless($driver->role === UserRole::DRIVER, 422, 'O utilizador escolhido não é um condutor.');
        abort_unless((int) $driver->municipality_id === (int) $order->municipality_id, 422, 'Este condutor não está registado no município do pedido.');
        abort_unless($driver->driver_available, 422, 'Este condutor não está disponível neste momento.');
        // Stock (Outubro 2026): só começa a entrega quem tem garrafas suficientes.
        app(DriverStockService::class)->assertCanStart($driver, $order);

        return $this->respondWith($this->gasOrderService->assign($order, $driver, $request->user()));
    }

    public function delivery(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);

        return $this->respondWith($this->gasOrderService->startDelivery($order, $request->user()));
    }

    /**
     * RESTRIÇÃO DE PAPEL (pedido explícito do cliente, Setembro 2026):
     * "a opção de cancelar ou feita só pode ser marcada pelo condutor
     * ou o super administrador e nunca os administradores". Assim que
     * um pedido tem um Condutor atribuído, um ADMIN comum (mesmo da
     * província certa, mesmo com `orders.manage`) deixa de poder
     * concluí-lo ou cancelá-lo por esta via — só o SUPER_ADMIN, ou o
     * próprio condutor pela sua rota dedicada (`DriverOrderController`,
     * que valida `assigned_to === auth()->id()` em vez desta regra).
     */
    public function complete(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);
        $this->assertNotDriverLocked($request, $order);

        return $this->respondWith($this->gasOrderService->complete($order, $request->user()));
    }

    public function cancel(Request $request, GasOrder $order): JsonResponse
    {
        $this->assertProvinceAccess($request, $order);
        $this->assertNotDriverLocked($request, $order);

        $reason = $request->input('reason');

        return $this->respondWith($this->gasOrderService->cancel($order, $request->user(), $reason));
    }

    private function assertNotDriverLocked(Request $request, GasOrder $order): void
    {
        if ($request->user()->hasSuperPowers()) {
            return; // Super Admin geral ou provincial (a província já foi verificada).
        }

        if ($order->assignedUser?->role === UserRole::DRIVER) {
            abort(403, 'Este pedido está atribuído a um condutor — só o próprio condutor ou o Super Admin pode marcá-lo como feito/cancelado.');
        }
    }

    /**
     * Correção de um preço já registado (pedido explícito do cliente,
     * Setembro 2026) — estritamente SUPER_ADMIN, nunca um Admin comum,
     * mesmo que o pedido seja da própria província dele. O Admin que
     * confirmou o pedido fica registado para sempre em `price_set_by`
     * (nunca é sobrescrito por uma correção); esta ação só atualiza
     * `price_corrected_by`/`price_corrected_at` e grava o valor
     * antigo/novo em `AuditLog` — nunca é uma correção silenciosa.
     *
     * Verificação de papel feita aqui dentro, não só via permissão
     * (mesmo padrão de `StationAdminController::toggleAnalytics` e
     * `UserAdminController`), porque `orders.manage` é uma permissão
     * que o Admin comum também tem — isto tem de ser mais restrito.
     */
    public function updatePrice(Request $request, GasOrder $order): JsonResponse
    {
        abort_unless($request->user()->hasSuperPowers(), 403, 'Só o Super Admin pode corrigir um preço já registado.');
        $this->assertProvinceAccess($request, $order);

        abort_if($order->delivery_price === null, 422, 'Este pedido ainda não tem preço registado — confirme o pedido primeiro.');

        $oldValues = [
            'delivery_price' => (float) $order->delivery_price,
            'bottle_price' => $order->bottle_price !== null ? (float) $order->bottle_price : null,
            'commission' => $order->commission !== null ? (float) $order->commission : null,
        ];

        if ($request->has('bottle_price') || $request->has('commission')) {
            $data = $request->validate([
                'bottle_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
                'commission' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            ]);

            $update = [
                'bottle_price' => round((float) $data['bottle_price'], 2),
                'commission' => round((float) $data['commission'], 2),
                'delivery_price' => round((float) $data['bottle_price'] + (float) $data['commission'], 2),
            ];
        } else {
            // Pedido antigo, sem divisão: só se corrige o total.
            abort_if($order->hasBillingSplit(), 422, 'Este pedido tem divisão registada — envie o valor das garrafas e a comissão.');

            $data = $request->validate([
                'delivery_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            ]);
            $update = ['delivery_price' => round((float) $data['delivery_price'], 2)];
        }

        $order->update($update + [
            'price_corrected_by' => $request->user()->id,
            'price_corrected_at' => now(),
        ]);

        $this->audit($request, 'order.price_corrected', $order, $oldValues, array_intersect_key(
            $update,
            $oldValues
        ));

        return $this->respondWith($order->fresh());
    }

    private function audit(Request $request, string $action, GasOrder $order, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => GasOrder::class,
            'entity_id' => $order->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Organização por categoria → província → município (Outubro 2026):
     * contagens para as abas e para as secções do painel. Mesma consulta e
     * mesmo âmbito da lista.
     */
    public function groups(Request $request): JsonResponse
    {
        $counts = $this->listQuery($request, withStatus: false)->reorder()
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $tabs = collect(GasOrderStatus::cases())->map(fn (GasOrderStatus $s) => [
            'key' => $s->value,
            'count' => (int) ($counts[$s->value] ?? 0),
        ])->values();

        return response()->json(['success' => true, 'data' => [
            'tabs' => $tabs,
            'groups' => LocationGroups::byColumns($this->listQuery($request, withStatus: true)),
        ]]);
    }

    private function listQuery(Request $request, bool $withStatus): Builder
    {
        $query = GasOrder::query();
        $this->scopeToAdminProvince($request, $query);

        if ($withStatus && ($status = $request->query('status'))) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('order_reference', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%"));
        }

        return $query;
    }

    /**
     * Restringe a listagem à província do ADMIN autenticado. Um ADMIN
     * sem `province_id` (conta antiga, criada antes desta funcionalidade,
     * ou mal configurada) não vê nada em vez de ver tudo — o padrão
     * seguro é "sem âmbito definido = sem acesso", nunca o contrário.
     * (Na prática, `UpdateUserAdminRequest` já impede que uma conta
     * fique ADMIN sem província a partir de agora — isto é só a rede de
     * segurança do lado da leitura.)
     */
    private function scopeToAdminProvince(Request $request, Builder $query): void
    {
        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        }
    }

    /**
     * @throws HttpException se o ADMIN autenticado não tiver acesso a
     *         este pedido concreto (província diferente da sua).
     */
    private function assertProvinceAccess(Request $request, GasOrder $order): void
    {
        if (!$this->isProvinceScopedAdmin($request)) {
            return; // SUPER_ADMIN (e qualquer outro papel autorizado pela rota) não tem restrição.
        }

        if ((int) $order->province_id !== $this->adminProvinceId($request)) {
            abort(403, 'Este pedido pertence a outra província — fora do âmbito da sua conta de administrador.');
        }
    }

    private function respondWith(GasOrder $order): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new GasOrderResource($order->load(['priceSetBy', 'priceCorrectedBy']))]);
    }
}
