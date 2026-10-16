<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\QueueLevel;
use App\Enums\StationUpdateSource;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStationRequest;
use App\Http\Resources\AdminStationResource;
use App\Models\AuditLog;
use App\Models\Station;
use App\Models\User;
use App\Services\StationStatusService;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

/**
 * CRUD administrativo de postos (secção 37). Toda alteração relevante
 * gera um audit log (secção 45), com valores antigos e novos.
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só
 * gere (vê/cria/edita/remove/atualiza estado) postos da SUA província.
 * SUPER_ADMIN mantém o CRUD completo, sem restrição. A criação/edição
 * é bloqueada na origem em `StoreStationRequest` (nunca deixa um ADMIN
 * submeter `province_id`/`municipality_id` fora da sua própria
 * província) — aqui só falta impedir o acesso a um posto já existente
 * de outra província via `assertProvinceAccess()`, pela mesma razão já
 * documentada em `AdminOrderController`: o route model binding
 * resolve `{station}` pelo ID, ignorando o filtro de `index()`.
 */
class StationAdminController extends Controller
{
    use ScopesToAdminProvince;

    public function __construct(private readonly StationStatusService $stationStatusService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Station::query()->with(['province', 'municipality', 'operator']);

        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($municipalityId = $request->query('municipality_id')) {
            $query->where('municipality_id', $municipalityId);
        }

        $stations = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => AdminStationResource::collection($stations),
            'meta' => ['current_page' => $stations->currentPage(), 'last_page' => $stations->lastPage(), 'total' => $stations->total()],
        ]);
    }

    public function store(StoreStationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);

        $station = Station::create($data);

        $this->syncOperatorRole(null, $data['operator_id'] ?? null);

        $this->audit($request, 'station.created', $station->id, null, $station->toArray());

        return response()->json(['success' => true, 'data' => new AdminStationResource($station->load(['province', 'municipality', 'operator']))], 201);
    }

    public function update(StoreStationRequest $request, Station $station): JsonResponse
    {
        $this->assertProvinceAccess($request, $station);

        $oldValues = $station->toArray();
        $previousOperatorId = $station->operator_id;

        $data = $request->validated();
        $station->update($data);

        $this->syncOperatorRole($previousOperatorId, $data['operator_id'] ?? null);

        $this->audit($request, 'station.updated', $station->id, $oldValues, $station->fresh()->toArray());

        return response()->json(['success' => true, 'data' => new AdminStationResource($station->fresh(['province', 'municipality', 'operator']))]);
    }

    public function destroy(Request $request, Station $station): JsonResponse
    {
        $this->assertProvinceAccess($request, $station);

        $oldValues = $station->toArray();
        $previousOperatorId = $station->operator_id;

        $station->delete(); // soft delete

        $this->syncOperatorRole($previousOperatorId, null);

        $this->audit($request, 'station.deleted', $station->id, $oldValues, null);

        return response()->json(['success' => true, 'message' => 'Posto removido.']);
    }

    /**
     * Mantém `role = STATION_OPERATOR` sincronizado com "opera
     * atualmente um posto" — um invariante simples e previsível:
     *
     * - Ao atribuir um operador novo, promove-o a STATION_OPERATOR
     *   (se ainda não o for). A validação em StoreStationRequest já
     *   garantiu que não é Admin/Super Admin/Moderador nem já opera
     *   outro posto.
     * - Ao remover/trocar o operador anterior, reverte-o para USER —
     *   evita ficarem contas "Operador" penduradas sem posto nenhum,
     *   o que seria confuso tanto para quem gere utilizadores como
     *   para a própria pessoa (o painel do operador exige um posto
     *   atribuído; sem isto, ela veria sempre "sem posto atribuído"
     *   apesar do papel continuar a dizer Operador).
     */
    private function syncOperatorRole(?int $previousOperatorId, ?int $newOperatorId): void
    {
        if ($previousOperatorId === $newOperatorId) {
            return;
        }

        if ($previousOperatorId) {
            User::where('id', $previousOperatorId)
                ->where('role', UserRole::STATION_OPERATOR)
                ->update(['role' => UserRole::USER]);
        }

        if ($newOperatorId) {
            User::where('id', $newOperatorId)
                ->where('role', '!=', UserRole::STATION_OPERATOR)
                ->update(['role' => UserRole::STATION_OPERATOR]);
        }
    }

    /**
     * Atualização manual de estado operacional (disponibilidade, fila e
     * tipos de garrafa) pela web — equivalente ao painel do operador,
     * mas para o Admin poder atualizar qualquer posto diretamente.
     * O SMS nunca escreve em current_cylinder_types (fica sempre com o
     * valor da última atualização manual feita aqui ou pelo operador).
     */
    public function updateStatus(Request $request, Station $station): JsonResponse
    {
        $this->assertProvinceAccess($request, $station);

        $data = $request->validate([
            'gas_available' => ['required', 'boolean'],
            'queue_level' => ['required', new Enum(QueueLevel::class)],
            'cylinder_types' => ['nullable', 'string', 'max:255'],
        ]);

        $this->stationStatusService->applyUpdate(
            $station,
            $data['gas_available'],
            QueueLevel::from($data['queue_level']),
            StationUpdateSource::ADMIN,
            $request->user(),
        );

        if (array_key_exists('cylinder_types', $data) && $data['cylinder_types'] !== null) {
            $parts = array_filter(array_map('trim', explode(',', $data['cylinder_types'])), fn ($p) => $p !== '');
            $station->update(['current_cylinder_types' => implode(', ', $parts)]);
        }

        $this->audit($request, 'station.status_updated', $station->id, null, $data);

        return response()->json([
            'success' => true,
            'data' => new AdminStationResource($station->fresh(['province', 'municipality'])),
        ]);
    }

    /**
     * Liga/desliga o acesso do OPERADOR ao seu próprio dashboard de
     * analytics/exportação (pedido explícito: liberdade exclusiva do
     * Super Admin, nem o Admin comum a tem). Verificação de papel
     * feita aqui dentro, não apenas via permissão — o mesmo padrão já
     * usado em UserAdminController para a diferença Admin/Super Admin,
     * porque `analytics.export`/`stations.update` são permissões que o
     * Admin também tem; isto tem de ser mais restrito do que isso.
     *
     * Nunca afeta o que o Admin/Super Admin veem em /admin/analytics —
     * só restringe o autoatendimento do operador ao SEU posto.
     */
    public function toggleAnalytics(Request $request, Station $station): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::SUPER_ADMIN, 403, 'Só o Super Admin pode ativar ou desativar o analytics de um posto.');

        $station->update(['analytics_enabled' => !$station->analytics_enabled]);

        $this->audit(
            $request,
            'station.analytics_toggled',
            $station->id,
            ['analytics_enabled' => !$station->analytics_enabled],
            ['analytics_enabled' => $station->analytics_enabled]
        );

        return response()->json(['success' => true, 'data' => new AdminStationResource($station->fresh(['province', 'municipality', 'operator']))]);
    }

    private function assertProvinceAccess(Request $request, Station $station): void
    {
        if (!$this->isProvinceScopedAdmin($request)) {
            return; // SUPER_ADMIN não tem restrição.
        }

        if ((int) $station->province_id !== $this->adminProvinceId($request)) {
            abort(403, 'Este posto pertence a outra província — fora do âmbito da sua conta de administrador.');
        }
    }

    private function audit(Request $request, string $action, int $entityId, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => Station::class,
            'entity_id' => $entityId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
