<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
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
}
