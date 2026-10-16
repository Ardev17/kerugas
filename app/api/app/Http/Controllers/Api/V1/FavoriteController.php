<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FavoriteResource;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = $request->user()->favorites()->with('station.province', 'station.municipality')->latest()->get();

        return response()->json(['success' => true, 'data' => FavoriteResource::collection($favorites)]);
    }

    public function store(Request $request, Station $station): JsonResponse
    {
        $favorite = $request->user()->favorites()->firstOrCreate(['station_id' => $station->id]);

        return response()->json(['success' => true, 'data' => new FavoriteResource($favorite->load('station'))], 201);
    }

    public function destroy(Request $request, Station $station): JsonResponse
    {
        $request->user()->favorites()->where('station_id', $station->id)->delete();

        return response()->json(['success' => true, 'message' => 'Removido dos favoritos.']);
    }
}
