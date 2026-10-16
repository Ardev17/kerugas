<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BottlePickup;
use App\Models\Station;
use App\Models\StationCounterReset;
use App\Services\Stock\PickupService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carregamentos e contadores dos postos (Outubro 2026). ADMIN vê os
 * carregamentos dos condutores da sua província; o Super Admin Provincial
 * também reinicia contadores dos postos da sua província; SUPER_ADMIN
 * geral vê e reinicia tudo.
 */
class PickupAdminController extends Controller
{
    public function __construct(private readonly PickupService $pickups, private readonly \App\Services\Stock\DriverStockAlertService $alerts)
    {
    }

    /** Condutores cujas garrafas não batem certo com as entregas feitas pela plataforma. */
    public function alerts(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->alerts->forAdmin($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'station_id' => ['nullable', 'integer'],
        ]);

        $q = $this->pickups->scoped($request->user())->with(['station', 'driver', 'confirmer', 'registrar']);

        if (!empty($data['station_id'])) {
            $q->where('station_id', $data['station_id']);
        }

        if (!empty($data['date'])) {
            $tz = $this->pickups->timezone();
            $day = Carbon::createFromFormat('Y-m-d', $data['date'], $tz)->startOfDay();
            $q->whereBetween('registered_at', [
                $day->copy()->setTimezone(config('app.timezone')),
                $day->copy()->endOfDay()->setTimezone(config('app.timezone')),
            ]);
        }

        $list = $q->latest('registered_at')->limit(200)->get();

        // Contadores: postos com carregamentos no âmbito de quem pede.
        $stationIds = $this->pickups->scoped($request->user())->distinct()->pluck('station_id');
        $names = Station::withTrashed()->whereIn('id', $stationIds)->pluck('name', 'id');
        $counters = collect($this->pickups->counters($stationIds))
            ->map(fn ($c, $id) => ['station_id' => (int) $id, 'station_name' => $names[$id] ?? 'Posto removido'] + $c)
            ->sortBy('station_name')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'pickups' => $list->map->toApi()->values(),
                'counters' => $counters,
                'resets' => StationCounterReset::with(['station', 'resetter'])->whereIn('station_id', $stationIds)
                    ->latest('reset_at')->limit(30)->get()->map(fn ($r) => [
                        'id' => $r->id,
                        'station_name' => $r->station?->name,
                        'reset_by' => $r->resetter?->name,
                        'bottles_before' => $r->bottles_before,
                        'reset_at' => $r->reset_at?->toIso8601String(),
                    ])->values(),
            ],
        ]);
    }

    public function correctAmount(Request $request, BottlePickup $pickup): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:99999999.99']]);

        return response()->json(['success' => true, 'data' => $this->pickups->correctAmount($request, $pickup, (float) $data['amount'])->toApi()]);
    }

    public function resetCounter(Request $request, Station $station): JsonResponse
    {
        abort_unless($request->user()->hasSuperPowers(), 403, 'Só o Super Admin reinicia o contador.');
        abort_unless($request->user()->canAccessProvince($station->province_id), 403, 'Este posto pertence a outra província.');

        $reset = $this->pickups->reset($request, $station);

        return response()->json(['success' => true, 'data' => [
            'station_id' => $station->id,
            'bottles_before' => $reset->bottles_before,
            'reset_at' => $reset->reset_at->toIso8601String(),
        ]]);
    }
}
