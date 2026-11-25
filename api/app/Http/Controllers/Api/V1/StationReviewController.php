<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Stations\StationPublicGate;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\StationReviewResource;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliações de postos (secção 33). Um utilizador só pode avaliar
 * cada posto uma vez,a unicidade é garantida também a nível de
 * base de dados (constraint station_id+user_id), evitando corrida
 * entre pedidos concorrentes.
 */
class StationReviewController extends Controller
{
    public function __construct(private readonly StationPublicGate $gate)
    {
    }

    public function index(Request $request, Station $station): JsonResponse
    {
        if ($recusa = $this->gate->check($request, $station)) {
            return $recusa;
        }

        $reviews = $station->reviews()->with('user')->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => StationReviewResource::collection($reviews),
            'meta' => ['current_page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage()],
        ]);
    }

    public function store(StoreReviewRequest $request, Station $station): JsonResponse
    {
        if ($recusa = $this->gate->check($request, $station)) {
            return $recusa;
        }

        $existing = $station->reviews()->where('user_id', $request->user()->id)->first();

        if ($existing) {
            $existing->update($request->validated());
            $review = $existing;
        } else {
            $review = $station->reviews()->create([
                ...$request->validated(),
                'user_id' => $request->user()->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new StationReviewResource($review->load('user')),
        ], 201);
    }
}
