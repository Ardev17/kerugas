<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReportStatusRequest;
use App\Models\AuditLog;
use App\Models\StationReport;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workflow de denúncias (secção 40): OPEN -> UNDER_REVIEW -> RESOLVED/REJECTED.
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só vê e
 * só consegue tratar denúncias de postos DA SUA província — a mesma
 * lógica de `CommentModerationController` (a província vem sempre do
 * posto denunciado, `station.province_id`, não de uma coluna própria).
 * SUPER_ADMIN mantém-se sem restrição.
 */
class ReportAdminController extends Controller
{
    use ScopesToAdminProvince;

    public function index(Request $request): JsonResponse
    {
        $query = StationReport::query()->with(['user', 'station']);

        if ($this->isProvinceScopedAdmin($request)) {
            $provinceId = $this->adminProvinceId($request);
            $query->whereHas('station', fn ($q) => $q->where('province_id', $provinceId));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $reports = $query->latest()->paginate(20);

        // BUG CORRIGIDO: $reports->through(...) devolve o LengthAwarePaginator
        // inteiro (com current_page, data, links, etc. lá dentro) — o
        // frontend espera 'data' como array simples, como as outras rotas
        // admin já fazem. ->items() extrai só a página atual como array puro.
        $transformed = collect($reports->items())->map(fn ($r) => [
            'id' => $r->id,
            'station' => $r->station?->name,
            'user' => $r->user?->name,
            'reason' => $r->reason->value,
            'description' => $r->description,
            'status' => $r->status->value,
            'created_at' => $r->created_at->toIso8601String(),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $transformed,
            'meta' => ['current_page' => $reports->currentPage(), 'last_page' => $reports->lastPage()],
        ]);
    }

    public function updateStatus(UpdateReportStatusRequest $request, StationReport $report): JsonResponse
    {
        $this->assertProvinceAccess($request, $report);

        $oldStatus = $report->status->value;

        $report->update([
            'status' => $request->validated('status'),
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        // AUDITORIA DE SEGURANÇA (Setembro 2026): `resolved_by`/
        // `resolved_at` só guardam o ÚLTIMO estado — se a denúncia mudar
        // de estado várias vezes, quem resolveu antes fica sem registo
        // nenhum. O AuditLog preserva o histórico completo (quem, quando,
        // de que estado para que estado), o que também importa se uma
        // conta de Admin for comprometida e usada para rejeitar/aprovar
        // denúncias sem ninguém notar.
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'report.status_updated',
            'entity_type' => StationReport::class,
            'entity_id' => $report->id,
            'old_values' => ['status' => $oldStatus],
            'new_values' => ['status' => $report->status->value],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $report->id, 'status' => $report->status->value]]);
    }

    private function assertProvinceAccess(Request $request, StationReport $report): void
    {
        if (!$this->isProvinceScopedAdmin($request)) {
            return;
        }

        if ((int) $report->station?->province_id !== $this->adminProvinceId($request)) {
            abort(403, 'Esta denúncia pertence a um posto de outra província — fora do âmbito da sua conta de administrador.');
        }
    }
}
