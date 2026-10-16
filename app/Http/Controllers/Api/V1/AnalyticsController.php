<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrackAnalyticsEventRequest;
use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint de tracking leve (secção 102). Nunca envia dados pessoais
 * desnecessários — o payload deve conter apenas o contexto mínimo
 * (ex: município pesquisado, id do posto visto), nunca PII.
 */
class AnalyticsController extends Controller
{
    public function track(TrackAnalyticsEventRequest $request): JsonResponse
    {
        AnalyticsEvent::create([
            // Rota pública: sem `auth:sanctum`, só o guard `sanctum`
            // explícito lê o Bearer token (ver GasOrderController::store).
            'user_id' => $request->user('sanctum')?->id,
            'event' => $request->validated('event'),
            'payload' => $request->validated('payload'),
            'session_id' => $request->validated('session_id'),
        ]);

        return response()->json(['success' => true]);
    }
}
