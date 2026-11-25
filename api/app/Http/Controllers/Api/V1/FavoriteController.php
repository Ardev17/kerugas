<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Stations\StationDataAccessService;
use App\Services\Stations\StationListingService;
use App\Services\Stations\StationPublicGate;
use App\Http\Controllers\Controller;
use App\Http\Resources\FavoriteResource;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function __construct(
        private readonly StationDataAccessService $access,
        private readonly StationListingService $listing,
        private readonly StationPublicGate $gate,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /**
         * Os favoritos guardam o posto inteiro, estado do gás incluído. Sem
         * isto eram a porta das traseiras dos dois bloqueios (Novembro 2026):
         * bastava ter o posto nos favoritos para continuar a ver se há gás
         * num município fechado, ou num posto sem presença paga.
         */
        $utilizador = $request->user();
        $municipiosFechados = $this->access->blockedMunicipalityIds($utilizador);
        $semPresenca = $this->listing->hiddenStationIds();

        $favorites = $utilizador->favorites()
            ->with('station.province', 'station.municipality')
            ->latest()
            ->get();

        $escondidos = $favorites->filter(fn ($f) => $f->station !== null && (
            in_array($f->station->municipality_id, $municipiosFechados, true)
            || in_array($f->station->id, $semPresenca, true)
        ));

        $visiveis = $favorites->reject(fn ($f) => $escondidos->contains('id', $f->id));

        return response()->json([
            'success' => true,
            'data' => FavoriteResource::collection($visiveis->values()),
            'meta' => [
                // Nunca se esconde em silêncio: o ecrã explica porque é que
                // faltam favoritos, com as mesmas palavras do resto da app.
                'hidden_count' => $escondidos->count(),
                'blocked_municipalities' => $zonas = $this->access->describeBlocked(
                    $escondidos->pluck('station.municipality_id')->unique()->filter()->values()->all()
                ),
                'access_block' => $this->access->listBlock($utilizador, $zonas->isNotEmpty()),
            ],
        ]);
    }

    public function store(Request $request, Station $station): JsonResponse
    {
        // A resposta leva o posto inteiro, gás incluído: antes desta porta,
        // bastava pedir para adicionar aos favoritos para ler o estado de
        // qualquer posto, pago ou não (auditoria de segurança, Novembro 2026).
        if ($recusa = $this->gate->check($request, $station)) {
            return $recusa;
        }

        $favorite = $request->user()->favorites()->firstOrCreate(['station_id' => $station->id]);

        return response()->json(['success' => true, 'data' => new FavoriteResource($favorite->load('station'))], 201);
    }

    public function destroy(Request $request, Station $station): JsonResponse
    {
        $request->user()->favorites()->where('station_id', $station->id)->delete();

        return response()->json(['success' => true, 'message' => 'Removido dos favoritos.']);
    }
}
