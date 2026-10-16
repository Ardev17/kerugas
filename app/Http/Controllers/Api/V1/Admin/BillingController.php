<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Station;
use App\Models\User;
use App\Services\Billing\BillingReportService;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Faturação (KeruGas intermediária, Outubro 2026) — ver BillingService
 * para as regras. ÂMBITO: cada ADMIN vê só os pedidos que ele confirmou;
 * carregamentos/condutores da sua província. O Super Admin Provincial vê
 * tudo da sua província e desfaz registos dela; o SUPER_ADMIN geral vê tudo.
 */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json([
            'success' => true,
            'data' => $this->billing->overview($request, $data['date'] ?? null)
                + ['scope' => match (true) {
                    $request->user()->isGlobalSuperAdmin() => 'all',
                    $request->user()->isProvinceSuperAdmin() => 'province',
                    default => 'own',
                }],
        ]);
    }

    /**
     * Relatórios (Outubro 2026): fecho do dia, dívida aos postos e dinheiro
     * dos condutores, em Excel (csv) ou PDF — mesmo âmbito da Faturação.
     */
    public function export(Request $request, BillingReportService $reports)
    {
        $data = $request->validate([
            'type' => ['required', 'in:' . implode(',', BillingReportService::TYPES)],
            'format' => ['required', 'in:csv,pdf'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        try {
            return $reports->download($reports->build($request, $data['type'], $data), $data['format']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public function payStation(Request $request, Station $station): JsonResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['success' => true, 'data' => $this->billing->payStation($request, $station, $data['until'] ?? null)]);
    }

    public function receiveCash(Request $request, User $driver): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['success' => true, 'data' => $this->billing->receiveCash($request, $driver, (float) $data['amount'], $data['note'] ?? null)], 201);
    }

    /** Livro completo — só Super Admin (visibilidade sobre o que os Admins fazem). */
    public function ledger(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasSuperPowers(), 403, 'Só o Super Admin vê o registo completo.');

        return response()->json(['success' => true, 'data' => $this->billing->ledgerList($request->user()->scopedProvinceId())]);
    }

    public function undo(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_unless($request->user()->hasSuperPowers(), 403, 'Só o Super Admin pode desfazer um registo.');

        return response()->json(['success' => true, 'data' => $this->billing->undo($request, $auditLog)]);
    }
}
