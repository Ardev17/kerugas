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
            $lat = (float) $data['latitude'];
            $lng = (float) $data['longitude'];

            // Caixa envolvente em SQL (portátil e barata: só postos à volta)
            // + distância exata em PHP. Antes usava `acos(...)` direto no SQL
            // com as coordenadas coladas na query: (1) por arredondamento o
            // argumento do acos podia passar de 1 e dar NULL, e o posto que
            // estava EXATAMENTE onde o cliente está desaparecia da lista;
            // (2) só corria em MySQL, por isso nunca teve testes.
            $latDelta = $radiusKm / 111.32;
            $lngDelta = $radiusKm / (111.32 * max(cos(deg2rad($lat)), 0.01));

            $stations = $query
                ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
                ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
                ->get()
                ->each(fn (Station $station) => $station->setAttribute('distance_km', $station->distanceFromKm($lat, $lng)))
                ->filter(fn (Station $station) => $station->distance_km <= $radiusKm)
                ->sortBy('distance_km')
                ->take(50)
                ->values();
        } else {
            $stations = $query->where('municipality_id', $data['municipality_id'])
                ->orderByDesc('current_confidence_score')
                ->limit(50)
                ->get();
        }

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
