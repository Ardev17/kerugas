<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NearbyStationsRequest;
use App\Http\Resources\StationResource;
use App\Models\Station;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    /**
     * GET /api/v1/stations/nearby
     *
     * Busca postos próximos por coordenadas (Haversine em SQL) ou,
     * na ausência de geolocalização, por município (fallback manual).
     */
    public function nearby(NearbyStationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $radiusKm = $data['radius_km'] ?? 15;

        $query = Station::query()
            ->with(['province', 'municipality'])
            ->where('status', 'ACTIVE');

        if (!empty($data['gas_available'])) {
            $query->where('current_gas_available', true);
        }

        if (!empty($data['queue_level'])) {
            $query->where('current_queue_level', $data['queue_level']);
        }

        if (isset($data['latitude'], $data['longitude'])) {
            $lat = $data['latitude'];
            $lng = $data['longitude'];

            $haversine = "(6371 * acos(cos(radians($lat)) * cos(radians(latitude))
                * cos(radians(longitude) - radians($lng)) + sin(radians($lat))
                * sin(radians(latitude))))";

            $query->selectRaw("stations.*, {$haversine} AS distance_km")
                ->havingRaw("distance_km <= ?", [$radiusKm])
                ->orderBy('distance_km');
        } else {
            $query->where('municipality_id', $data['municipality_id'])
                ->orderByDesc('current_confidence_score');
        }

        $stations = $query->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => StationResource::collection($stations),
            'meta' => ['count' => $stations->count()],
        ]);
    }

    public function show(Station $station): JsonResponse
    {
        $station->load(['province', 'municipality']);

        return response()->json([
            'success' => true,
            'data' => new StationResource($station),
        ]);
    }

    /**
     * GET /api/v1/map/stations — marcadores do mapa.
     *
     * BUG CORRIGIDO (nº 2): esta query ficava em cache por 60 segundos
     * sob a chave 'map:stations:active', mas NADA invalidava essa
     * cache quando um posto mudava de estado — resultado: atualizações
     * feitas pelo Operador/Admin não apareciam no mapa até a cache
     * expirar (e em alguns ambientes de ficheiros no Windows, nem
     * sempre expirava de forma fiável). Para um volume de postos desta
     * escala, o custo de uma query sem cache é insignificante — a
     * frescura dos dados importa mais aqui do que a micro-otimização.
     */
    public function forMap(): JsonResponse
    {
        $stations = Station::query()
            ->where('status', 'ACTIVE')
            ->get();

        return response()->json([
            'success' => true,
            'data' => StationResource::collection($stations),
        ]);
    }
}
