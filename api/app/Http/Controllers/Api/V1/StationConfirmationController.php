<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StationUpdateSource;
use App\Http\Controllers\Controller;
use App\Services\Stations\StationPublicGate;
use App\Http\Requests\StoreConfirmationRequest;
use App\Models\Station;
use App\Services\TrustScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Confirmação comunitária de disponibilidade/fila (secção 32).
 * Não substitui o status oficial via SMS,apenas alimenta o
 * TrustScoreService, aumentando ou reduzindo a confiança exibida.
 */
class StationConfirmationController extends Controller
{
    public function __construct(
        private readonly TrustScoreService $trustScoreService,
        private readonly StationPublicGate $gate,
    ) {
    }

    public function store(StoreConfirmationRequest $request, Station $station): JsonResponse
    {
        // Confirmar mexe na confiança do posto e devolve-a: só quem o pode
        // consultar (auditoria de segurança, Novembro 2026).
        if ($recusa = $this->gate->check($request, $station)) {
            return $recusa;
        }

        DB::transaction(function () use ($request, $station) {
            $station->confirmations()->create([
                ...$request->validated(),
                'user_id' => $request->user()->id,
            ]);

            $station->update([
                'current_confidence_score' => $this->trustScoreService->calculate($station),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Obrigado por confirmar. Isto ajuda outros utilizadores.',
            'data' => ['confidence_score' => $station->fresh()->current_confidence_score],
        ], 201);
    }
}
