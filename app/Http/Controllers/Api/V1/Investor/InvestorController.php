<?php

namespace App\Http\Controllers\Api\V1\Investor;

use App\Http\Controllers\Controller;
use App\Services\Investors\InvestorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel do investidor (Outubro 2026): só a faturação dos municípios em
 * vigor em seu nome. Regras em InvestorService.
 */
class InvestorController extends Controller
{
    public function __construct(private readonly InvestorService $investors)
    {
    }

    /** Exportar a SUA faturação (Excel ou PDF) — os mesmos números do painel. */
    public function export(Request $request, \App\Services\Billing\BillingReportService $reports)
    {
        $data = $request->validate([
            'format' => ['required', 'in:csv,pdf'],
            'municipality_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $report = $this->investors->report($request->user(), isset($data['municipality_id']) ? (int) $data['municipality_id'] : null, $data['from'] ?? null, $data['to'] ?? null);

        try {
            return $reports->download($report, $data['format']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'municipality_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['success' => true, 'data' => $this->investors->overview(
            $request->user(),
            isset($data['municipality_id']) ? (int) $data['municipality_id'] : null,
            $data['from'] ?? null,
            $data['to'] ?? null,
        )]);
    }
}
