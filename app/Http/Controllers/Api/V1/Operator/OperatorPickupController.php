<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BottlePickup;
use App\Models\Station;
use App\Services\Stock\PickupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Garrafas levadas pela KeruGás" do operador (Outubro 2026): contador
 * do posto, carregamentos registados pelos condutores e confirmar/contestar.
 */
class OperatorPickupController extends Controller
{
    public function __construct(private readonly PickupService $pickups)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        return response()->json([
            'success' => true,
            'data' => [
                'station' => ['id' => $station->id, 'name' => $station->name, 'code' => $station->code],
                'counter' => $this->pickups->counters(collect([$station->id]))[$station->id],
                'pickups' => BottlePickup::with(['station', 'driver', 'confirmer', 'registrar'])->where('station_id', $station->id)
                    ->latest('registered_at')->limit(50)->get()->map->toApi()->values(),
            ],
        ]);
    }

    public function confirm(Request $request, BottlePickup $pickup): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->pickups->confirm($request->user(), $pickup)->toApi()]);
    }

    public function dispute(Request $request, BottlePickup $pickup): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(['success' => true, 'data' => $this->pickups->dispute($request->user(), $pickup, $data['note'])->toApi()]);
    }

    private function myStation(Request $request): Station
    {
        $user = $request->user();
        abort_unless($user->role === UserRole::STATION_OPERATOR, 403, 'Apenas operadores de posto têm acesso a este painel.');
        $station = $user->operatedStation;
        abort_if(!$station, 404, 'Não existe posto atribuído à sua conta.');

        return $station;
    }
}
