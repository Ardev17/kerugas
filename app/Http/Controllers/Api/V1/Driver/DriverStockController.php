<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\StationStatus;
use App\Http\Controllers\Controller;
use App\Models\BottlePickup;
use App\Models\Station;
use App\Services\Stock\CentralPickupService;
use App\Services\Stock\DriverStockService;
use App\Services\Stock\PickupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock e carregamentos do próprio condutor (Outubro 2026). Prefixo
 * /driver já restrito a role:DRIVER em routes/api.php.
 */
class DriverStockController extends Controller
{
    public function __construct(
        private readonly DriverStockService $stock,
        private readonly PickupService $pickups,
    ) {
    }

    /** "Garrafas disponíveis" + últimos carregamentos. */
    public function show(Request $request): JsonResponse
    {
        $driver = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'stock' => $this->stock->forDriver($driver->id),
                // Registo pela central e PIN do SMS (Outubro 2026).
                'central_pickup' => [
                    'active' => $driver->centralPickupActive(),
                    'expires_at' => $driver->central_pickup_expires_at?->toIso8601String(),
                ],
                'sms_pin_set' => $driver->sms_pin_hash !== null,
                'sms_pin_temporary' => $driver->sms_pin_hash !== null && (bool) $driver->sms_pin_temporary,
                'pickups' => BottlePickup::with(['station', 'driver', 'confirmer', 'registrar'])
                    ->where('driver_id', $driver->id)->latest('registered_at')->limit(30)->get()
                    ->map->toApi()->values(),
            ],
        ]);
    }

    /** Postos ativos para escolher no carregamento (qualquer posto). */
    public function stations(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $stations = Station::query()->where('status', StationStatus::ACTIVE)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('code', $search)))
            ->with('municipality:id,name')->orderBy('name')->limit(30)->get(['id', 'code', 'name', 'municipality_id']);

        return response()->json([
            'success' => true,
            'data' => $stations->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'municipality' => $s->municipality?->name])->values(),
        ]);
    }

    /** Regista um carregamento (idempotente por client_uuid — a fila offline pode reenviar). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'registered_at' => ['nullable', 'date', 'after:-30 days'],
            'offline' => ['nullable', 'boolean'],
        ]);

        [$pickup, $created] = $this->pickups->register($request->user(), $data);

        return response()->json([
            'success' => true,
            'data' => ['pickup' => $pickup->toApi(), 'stock' => $this->stock->forDriver($request->user()->id)],
        ], $created ? 201 : 200);
    }

    /** O condutor confirma um carregamento que a central registou por ele. */
    public function confirmCentral(Request $request, BottlePickup $pickup): JsonResponse
    {
        return response()->json(['success' => true, 'data' => app(CentralPickupService::class)->confirmByDriver($request->user(), $pickup)->toApi()]);
    }

    /** ...ou contesta-o (vai para os Super Admins). */
    public function disputeCentral(Request $request, BottlePickup $pickup): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(['success' => true, 'data' => app(CentralPickupService::class)->disputeByDriver($request->user(), $pickup, $data['note'])->toApi()]);
    }
}
