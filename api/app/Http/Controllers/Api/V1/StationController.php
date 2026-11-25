<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NearbyStationsRequest;
use App\Http\Resources\StationResource;
use App\Models\Station;
use App\Services\Stations\StationDataAccessService;
use App\Services\Stations\StationListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function __construct(
        private readonly StationDataAccessService $access,
        private readonly StationListingService $listing,
        private readonly \App\Services\Stations\StationPublicGate $gate,
    ) {
    }

    /**
     * Quem está do outro lado, se é que está alguém.
     *
     * Estas rotas são públicas,não têm `auth:sanctum`,e por isso o guarda
     * por omissão (sessão) devolve sempre null, mesmo quando a aplicação manda
     * o token. Pergunta-se ao guarda do Sanctum à mão: com token válido vem o
     * utilizador, sem token vem null e a consulta segue como anónima.
     */
    private function visitante(Request $request): ?\App\Models\User
    {
        return $this->gate->visitor($request);
    }

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

        // Consulta fechada (Novembro 2026): os municípios por pagar saem da
        // lista. Não se devolve erro,devolve-se o que esta pessoa pode ver,
        // e em `meta` quais as zonas que ficaram de fora e porquê, para a
        // aplicação poder explicar em vez de as esconder em silêncio.
        $visitante = $this->visitante($request);
        $blocked = $this->access->blockedMunicipalityIds($visitante);

        // Presença paga (a dormir): com o mecanismo desligado isto é um array
        // vazio e nem sequer houve consulta.
        $semPresenca = $this->listing->hiddenStationIds();

        $query = Station::query()
            ->with(['province', 'municipality'])
            ->where('status', 'ACTIVE')
            ->when($blocked !== [], fn ($q) => $q->whereNotIn('municipality_id', $blocked))
            ->when($semPresenca !== [], fn ($q) => $q->whereNotIn('id', $semPresenca));

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

            $caixa = [
                'lat' => [$lat - $latDelta, $lat + $latDelta],
                'lng' => [$lng - $lngDelta, $lng + $lngDelta],
            ];

            $stations = $query
                ->whereBetween('latitude', $caixa['lat'])
                ->whereBetween('longitude', $caixa['lng'])
                ->get()
                ->each(fn (Station $station) => $station->setAttribute('distance_km', $station->distanceFromKm($lat, $lng)))
                ->filter(fn (Station $station) => $station->distance_km <= $radiusKm)
                ->sortBy('distance_km')
                ->take(50)
                ->values();
            // Só interessam as zonas fechadas que ESTA procura teria
            // apanhado. Dizer a quem procura em Benguela que o Lobito também
            // está fechado é ruído, e faz a mensagem parecer um aviso geral em
            // vez de uma explicação para o que ele está a ver.
            $relevantes = Station::query()
                ->where('status', 'ACTIVE')
                ->whereIn('municipality_id', $blocked)
                ->whereBetween('latitude', $caixa['lat'])
                ->whereBetween('longitude', $caixa['lng'])
                ->distinct()
                ->pluck('municipality_id')
                ->all();
        } else {
            $stations = $query->where('municipality_id', $data['municipality_id'])
                ->orderByDesc('current_confidence_score')
                ->limit(50)
                ->get();

            $relevantes = in_array((int) $data['municipality_id'], $blocked, true)
                ? [(int) $data['municipality_id']]
                : [];
        }

        return response()->json([
            'success' => true,
            'data' => StationResource::collection($stations),
            'meta' => [
                'count' => $stations->count(),
                'blocked_municipalities' => $zonas = $this->access->describeBlocked($relevantes),
                // Quando é a conta que está fechada, os municípios estão
                // abertos e `blocked_municipalities` vem vazio: sem isto a
                // aplicação não teria o que dizer.
                'access_block' => $this->access->listBlock($visitante, $zonas->isNotEmpty()),
            ],
        ]);
    }

    public function show(Request $request, Station $station): JsonResponse
    {
        $station->load(['province', 'municipality']);

        // Activo, com presença (a dormir) e consultável por quem pergunta —
        // a mesma porta dos favoritos, comentários e avaliações.
        if ($recusa = $this->gate->check($request, $station)) {
            return $recusa;
        }

        return response()->json([
            'success' => true,
            'data' => new StationResource($station),
        ]);
    }

    /**
     * GET /api/v1/map/stations,marcadores do mapa.
     *
     * BUG CORRIGIDO (nº 2): esta query ficava em cache por 60 segundos
     * sob a chave 'map:stations:active', mas NADA invalidava essa
     * cache quando um posto mudava de estado,resultado: atualizações
     * feitas pelo Operador/Admin não apareciam no mapa até a cache
     * expirar (e em alguns ambientes de ficheiros no Windows, nem
     * sempre expirava de forma fiável). Para um volume de postos desta
     * escala, o custo de uma query sem cache é insignificante,a
     * frescura dos dados importa mais aqui do que a micro-otimização.
     */
    public function forMap(Request $request): JsonResponse
    {
        $visitante = $this->visitante($request);
        $blocked = $this->access->blockedMunicipalityIds($visitante);

        $semPresenca = $this->listing->hiddenStationIds();

        $stations = Station::query()
            ->where('status', 'ACTIVE')
            ->when($blocked !== [], fn ($q) => $q->whereNotIn('municipality_id', $blocked))
            ->when($semPresenca !== [], fn ($q) => $q->whereNotIn('id', $semPresenca))
            ->get();

        return response()->json([
            'success' => true,
            'data' => StationResource::collection($stations),
            'meta' => [
                'blocked_municipalities' => $zonas = $this->access->describeBlocked($blocked),
                'access_block' => $this->access->listBlock($visitante, $zonas->isNotEmpty()),
            ],
        ]);
    }
}
