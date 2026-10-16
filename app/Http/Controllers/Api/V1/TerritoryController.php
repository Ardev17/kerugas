<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Services\Territory\TerritoryLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Fornece a hierarquia Província -> Município usada nos selects
 * dependentes do frontend (secção 48). Nunca permitir combinação
 * incompatível: o município devolvido é sempre filtrado pela província.
 */
class TerritoryController extends Controller
{
    public function provinces(): JsonResponse
    {
        $provinces = Cache::remember('territory:provinces', 3600, function () {
            return Province::orderBy('name')->get(['id', 'name', 'slug']);
        });

        return response()->json(['success' => true, 'data' => $provinces]);
    }

    public function municipalities(Province $province): JsonResponse
    {
        $municipalities = Cache::remember("territory:province:{$province->id}:municipalities", 3600, function () use ($province) {
            return $province->municipalities()->orderBy('name')->get(['id', 'name', 'slug']);
        });

        return response()->json(['success' => true, 'data' => $municipalities]);
    }

    /**
     * Província e município prováveis para umas coordenadas. Sempre 200:
     * quando não consegue deduzir, devolve nulls e o utilizador escolhe.
     */
    public function locate(Request $request, TerritoryLocator $locator): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $locator->locate((float) $data['latitude'], (float) $data['longitude']),
        ]);
    }
}
