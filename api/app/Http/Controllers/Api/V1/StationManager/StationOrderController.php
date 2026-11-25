<?php

namespace App\Http\Controllers\Api\V1\StationManager;

use App\Enums\GasOrderStatus;
use App\Enums\StationOrdersOverride;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\GasOrderResource;
use App\Models\GasOrder;
use App\Models\Station;
use App\Models\User;
use App\Services\GasOrderService;
use App\Services\Stations\StationListingService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pedidos do município para postos subscritos (Novembro 2026, decisão do
 * cliente). Usado pelo gerente (Admin de Posto) e pelos operadores do posto.
 *
 * - Com a subscrição ativa, o posto vê os pedidos ABERTOS do seu município
 *   (ainda sem posto nem condutor KeruGás) e os dados do cliente.
 * - Quem aceitar primeiro fica com o pedido; o posto define o preço
 *   acertado com o cliente (sem comissão KeruGás).
 * - O posto só atribui os seus pedidos aos SEUS condutores de posto.
 * - Pode devolver um pedido à KeruGás se não o conseguir entregar.
 * - Sem subscrição, o posto continua a ver e a terminar os pedidos que já
 *   aceitou, mas não vê nem aceita novos.
 */
class StationOrderController extends Controller
{
    public function __construct(
        private readonly GasOrderService $orders,
        private readonly SubscriptionService $subscriptions,
        private readonly StationListingService $listing,
    ) {
    }

    /** GET /station/orders?scope=open|mine */
    public function index(Request $request): JsonResponse
    {
        $station = $this->myStation($request);
        $subscription = $this->subscriptionInfo($station);
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'open';

        if ($scope === 'open' && !$subscription['can_accept']) {
            return response()->json(['success' => true, 'data' => [], 'meta' => ['subscription' => $subscription, 'scope' => $scope]]);
        }

        $query = GasOrder::query()->with(['province', 'municipality', 'assignedUser', 'acceptedStation']);

        if ($scope === 'open') {
            $query->where('municipality_id', $station->municipality_id)
                ->whereNull('station_id')
                ->whereNull('assigned_to')
                ->whereIn('status', array_map(fn ($s) => $s->value, GasOrderService::STATION_OPEN_STATUSES))
                ->oldest();
        } else {
            $query->where('station_id', $station->id);
            if ($status = $request->query('status')) {
                $query->where('status', $status);
            }
            $query->latest('accepted_at');
        }

        $page = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => GasOrderResource::collection($page),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'subscription' => $subscription, 'scope' => $scope,
                'drivers' => $station->stationDrivers()->where('status', 'ACTIVE')->get(['id', 'name', 'phone', 'driver_available'])
                    ->map(fn (User $d) => ['id' => $d->id, 'name' => $d->name, 'phone' => $d->phone, 'available' => (bool) $d->driver_available])->values(),
            ],
        ]);
    }

    /** GET /station/subscription — situação e como pagar. */
    public function subscription(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->subscriptionInfo($this->myStation($request))]);
    }

    public function accept(Request $request, GasOrder $order): JsonResponse
    {
        $station = $this->myStation($request);
        $info = $this->subscriptionInfo($station);
        abort_unless($info['can_accept'], 403, $info['override'] === StationOrdersOverride::BLOCK->value
            ? ($info['override_reason'] ?: 'A KeruGás suspendeu os pedidos deste posto. Fale connosco.')
            : 'A subscrição de pedidos do posto não está ativa. Renove para aceitar pedidos do município.');

        $data = $request->validate([
            'delivery_price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'cylinder_types_confirmed' => ['nullable', 'string', 'max:255'],
        ], ['delivery_price.required' => 'Indique o preço acertado com o cliente.']);

        abort_unless((int) $order->municipality_id === (int) $station->municipality_id, 403, 'Este pedido é de outro município.');

        $accepted = $this->orders->acceptByStation($order, $station, $request->user(), (float) $data['delivery_price'], $data['cylinder_types_confirmed'] ?? null);

        abort_if($accepted === null, 409, 'Este pedido já foi aceite por outro posto ou pela KeruGás.');

        return response()->json(['success' => true, 'data' => new GasOrderResource($accepted->load(['province', 'municipality', 'assignedUser', 'acceptedStation']))]);
    }

    public function assign(Request $request, GasOrder $order): JsonResponse
    {
        $station = $this->myStation($request);
        $this->assertOwnOrder($station, $order);

        $data = $request->validate(['driver_id' => ['required', 'integer']]);
        $driver = User::find($data['driver_id']);

        abort_unless($driver && $driver->role === UserRole::STATION_DRIVER && (int) $driver->driver_station_id === (int) $station->id, 422, 'Escolha um condutor deste posto.');
        abort_unless($driver->status === 'ACTIVE', 422, 'A conta deste condutor está suspensa.');
        abort_unless(in_array($order->status, [GasOrderStatus::CONFIRMED, GasOrderStatus::ASSIGNED], true), 422, 'Este pedido já não pode ser atribuído.');

        // Reatribuir: o condutor anterior fica livre.
        $previous = $order->assignedUser;
        if ($previous && (int) $previous->id !== (int) $driver->id && $previous->role === UserRole::STATION_DRIVER) {
            $previous->update(['driver_available' => true]);
        }

        $updated = $this->orders->assign($order, $driver, $request->user());

        return response()->json(['success' => true, 'data' => new GasOrderResource($updated->load(['province', 'municipality', 'assignedUser', 'acceptedStation']))]);
    }

    /** O posto devolve o pedido à KeruGás (não o consegue entregar). */
    public function release(Request $request, GasOrder $order): JsonResponse
    {
        $station = $this->myStation($request);
        $this->assertOwnOrder($station, $order);
        abort_unless(in_array($order->status, [GasOrderStatus::CONFIRMED, GasOrderStatus::ASSIGNED], true), 422, 'Este pedido já terminou.');

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $updated = $this->orders->releaseFromStation($order, $request->user(), $reason);

        return response()->json(['success' => true, 'data' => new GasOrderResource($updated->load(['province', 'municipality', 'assignedUser']))]);
    }

    // ───────────────────────── apoio

    private function myStation(Request $request): Station
    {
        $user = $request->user();

        $station = match ($user->role) {
            UserRole::STATION_MANAGER => $user->managedStation,
            UserRole::STATION_OPERATOR => $user->operatedStation,
            default => abort(403, 'Apenas o pessoal do posto tem acesso a estes pedidos.'),
        };

        abort_if(!$station, 404, 'Não existe posto atribuído à sua conta. Contacte o suporte do KERUGAS.');

        return $station->loadMissing('municipality');
    }

    private function assertOwnOrder(Station $station, GasOrder $order): void
    {
        abort_unless((int) $order->station_id === (int) $station->id, 404, 'Este pedido não pertence ao seu posto.');
    }

    /** @return array<string, mixed> */
    private function subscriptionInfo(Station $station): array
    {
        $settings = $this->subscriptions->settings();
        $active = $this->subscriptions->activeStationOrdersSubscription($station);
        $until = $active ? $this->subscriptions->stationOrdersPaidUntil($station) : null;
        $price = $settings->stationOrdersWeekPrice();

        // `active` é a subscrição paga. `can_accept` é a resposta que conta:
        // o interruptor do administrador ganha-lhe (Novembro 2026).
        return [
            'active' => $active !== null,
            'can_accept' => $station->canReceiveOrders($active !== null),
            'override' => ($station->orders_override ?? StationOrdersOverride::INHERIT)->value,
            'override_reason' => $station->orders_override_reason,
            'listing_enforced' => $this->listing->enforced(),
            'listing_visible' => $this->listing->isListed($station),
            'valid_until' => $until?->toIso8601String(),
            'municipality' => $station->municipality?->name,
            'price_per_week_kz' => $price,
            'price_label' => number_format($price, 0, ',', '.') . ' Kz por semana',
            'payment' => [
                'bank_name' => $settings->get('bank_name'),
                'account_holder' => $settings->get('account_holder'),
                'iban' => $settings->get('iban'),
                'contact' => $settings->contact(),
            ],
        ];
    }
}
