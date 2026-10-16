<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Denúncias de postos (secção 35). Nunca resolve automaticamente —
 * entra sempre como OPEN para revisão humana (secção 98: não bloquear
 * casos legítimos automaticamente, enviar para revisão).
 */
class StationReportController extends Controller
{
    public function store(StoreReportRequest $request, Station $station): JsonResponse
    {
        $report = $station->reports()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'status' => ReportStatus::OPEN,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Denúncia registada. A nossa equipa irá analisar.',
            'data' => ['id' => $report->id, 'status' => $report->status->value],
        ], 201);
    }
}
