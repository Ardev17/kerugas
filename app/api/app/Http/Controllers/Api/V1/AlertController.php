<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAlertRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alertas de disponibilidade (secção 22). O utilizador escolhe posto
 * OU município (não ambos em simultâneo obrigatoriamente) — os
 * listeners que disparam a notificação são implementados na Etapa 14
 * (jobs de fila), este controller apenas gere o CRUD.
 */
class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $alerts = $request->user()->alerts()->with(['station', 'municipality'])->latest()->get();

        return response()->json(['success' => true, 'data' => AlertResource::collection($alerts)]);
    }

    public function store(StoreAlertRequest $request): JsonResponse
    {
        $alert = $request->user()->alerts()->create([
            ...$request->validated(),
            'active' => true,
        ]);

        return response()->json(['success' => true, 'data' => new AlertResource($alert)], 201);
    }

    public function update(StoreAlertRequest $request, Alert $alert): JsonResponse
    {
        $this->authorizeOwnership($request, $alert);

        $alert->update($request->validated());

        return response()->json(['success' => true, 'data' => new AlertResource($alert)]);
    }

    public function destroy(Request $request, Alert $alert): JsonResponse
    {
        $this->authorizeOwnership($request, $alert);

        $alert->delete();

        return response()->json(['success' => true, 'message' => 'Alerta removido.']);
    }

    private function authorizeOwnership(Request $request, Alert $alert): void
    {
        abort_if($alert->user_id !== $request->user()->id, 403, 'Sem permissão sobre este alerta.');
    }
}
