<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\QueueLevel;
use App\Enums\StationStatus;
use App\Enums\StationUpdateSource;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStationRequest;
use App\Http\Resources\AdminStationResource;
use App\Models\AuditLog;
use App\Models\Station;
use App\Models\User;
use App\Enums\SmsDispatchStatus;
use App\Services\StationStatusService;
use App\Services\Subscriptions\SubscriptionNotifier;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SubscriptionSmsService;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use App\Support\LocationGroups;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(
        private readonly StationStatusService $stationStatusService,
        private readonly SubscriptionNotifier $subscriptionNotifier,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionSmsService $subscriptionSms,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery($request, withGas: true)->with(['province', 'municipality', 'operators']);
        LocationGroups::filterColumns($query, $request);

        $stations = ($request->filled('gas') ? $query->orderBy('name')->orderBy('id') : $query->latest()->latest('id'))->paginate(20);

        return response()->json([
            'success' => true,
            'data' => AdminStationResource::collection($stations),
            'meta' => ['current_page' => $stations->currentPage(), 'last_page' => $stations->lastPage(), 'total' => $stations->total()],
        ]);
    }

    /**
     * Organização por categoria → província → município (Outubro 2026):
     * contagens para as abas e para as secções do painel. Mesma consulta e
     * mesmo âmbito da lista.
     */
    public function groups(Request $request): JsonResponse
    {
        $base = $this->listQuery($request, withGas: false);
        $tabs = collect(['available', 'unavailable', 'unknown'])->map(fn (string $gas) => [
            'key' => $gas,
            'count' => $this->applyGas(clone $base, $gas)->count(),
        ])->values();

        $groups = $request->filled('gas') ? LocationGroups::byColumns($this->listQuery($request, withGas: true)) : [];

        return response()->json(['success' => true, 'data' => ['tabs' => $tabs, 'groups' => $groups]]);
    }

    private function listQuery(Request $request, bool $withGas): Builder
    {
        $query = Station::query();

        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('code', $search));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($withGas && is_string($request->query('gas')) && $request->filled('gas')) {
            $this->applyGas($query, (string) $request->query('gas'));
        }

        return $query;
    }

    /** Com gás / Sem gás / Sem informação (o posto nunca recebeu um estado). */
    private function applyGas(Builder $query, string $gas): Builder
    {
        return match ($gas) {
            'available' => $query->whereNotNull('status_updated_at')->where('current_gas_available', true),
            'unavailable' => $query->whereNotNull('status_updated_at')->where('current_gas_available', false),
            'unknown' => $query->whereNull('status_updated_at'),
            default => $query,
        };
    }

    public function store(StoreStationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $operatorIds = $data['operator_ids'] ?? null;
        unset($data['operator_ids']);
        $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);

        // O número de 5 dígitos é automático; se dois postos nascerem ao mesmo tempo, repete.
        $station = retry(3, fn () => Station::create($data), 0, fn ($e) => $e instanceof \Illuminate\Database\UniqueConstraintViolationException);

        if ($operatorIds !== null) {
            $this->syncOperators($station, $operatorIds);
        }

        $this->audit($request, 'station.created', $station->id, null, $station->toArray());

        return response()->json(['success' => true, 'data' => new AdminStationResource($station->load(['province', 'municipality', 'operators']))], 201);
    }

    public function update(StoreStationRequest $request, Station $station): JsonResponse
    {
        $this->assertProvinceAccess($request, $station);

        $oldValues = $station->toArray() + ['operator_ids' => $station->operatorIds()];

        $data = $request->validated();
        $operatorIds = $data['operator_ids'] ?? null;
        unset($data['operator_ids']);
        $station->update($data);

        if ($request->has('operator_ids')) {
            $this->syncOperators($station, $operatorIds ?? []);
        }

        $this->audit($request, 'station.updated', $station->id, $oldValues, $station->fresh()->toArray() + ['operator_ids' => $station->operatorIds()]);

        return response()->json(['success' => true, 'data' => new AdminStationResource($station->fresh(['province', 'municipality', 'operators']))]);
    }

    public function destroy(Request $request, Station $station): JsonResponse
    {
        $this->assertProvinceAccess($request, $station);

        $oldValues = $station->toArray() + ['operator_ids' => $station->operatorIds()];

        $station->delete(); // soft delete

        // Posto apagado: os operadores ficam livres (voltam a utilizador comum).
        $this->syncOperators($station, []);

        $this->audit($request, 'station.deleted', $station->id, $oldValues, null);

        return response()->json(['success' => true, 'message' => 'Posto removido.']);
    }

    /**
     * Mantém `role = STATION_OPERATOR` sincronizado com "opera
     * atualmente um posto" (um posto pode ter vários operadores, cada
     * operador só um posto — Outubro 2026):
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
    private function syncOperators(Station $station, array $operatorIds): void
    {
        $wanted = collect($operatorIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $current = collect($station->operatorIds());

        $removed = $current->diff($wanted)->values();
        $added = $wanted->diff($current)->values();

        if ($removed->isNotEmpty()) {
            User::whereIn('id', $removed)->update(['operated_station_id' => null]);
            User::whereIn('id', $removed)->where('role', UserRole::STATION_OPERATOR)->update(['role' => UserRole::USER]);
        }

        if ($added->isNotEmpty()) {
            User::whereIn('id', $added)->update(['operated_station_id' => $station->id]);
            User::whereIn('id', $added)->where('role', '!=', UserRole::STATION_OPERATOR)->update(['role' => UserRole::STATION_OPERATOR]);
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
            // Estado do posto (Ativo/Inativo/Pendente/Suspenso) — Outubro 2026:
            // o Admin comum já não edita o posto, mas muda o estado aqui.
            'status' => ['nullable', new Enum(StationStatus::class)],
        ]);

        if (!empty($data['status']) && $station->status?->value !== $data['status']) {
            $station->update(['status' => $data['status']]);
        }

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
        abort_unless($request->user()->hasSuperPowers(), 403, 'Só o Super Admin pode ativar ou desativar o analytics de um posto.');
        $this->assertProvinceAccess($request, $station);

        $station->update(['analytics_enabled' => !$station->analytics_enabled]);

        $this->audit(
            $request,
            'station.analytics_toggled',
            $station->id,
            ['analytics_enabled' => !$station->analytics_enabled],
            ['analytics_enabled' => $station->analytics_enabled]
        );

        // Aviso AUTOMÁTICO ao operador (Outubro 2026): notificação na
        // plataforma + push. Ao desativar, pede-lhe que atualize a
        // subscrição; ao reativar, avisa que já tem a versão completa.
        $station->loadMissing('operators');
        $operatorNotified = $station->analytics_enabled
            ? $this->subscriptionNotifier->analyticsEnabled($station)
            : $this->subscriptionNotifier->analyticsDisabled($station);

        return response()->json([
            'success' => true,
            'data' => new AdminStationResource($station->fresh(['province', 'municipality', 'operators'])),
            'meta' => ['operator_notified' => $operatorNotified],
        ]);
    }

    /**
     * Aviso MANUAL por SMS ao operador cujo posto tem o analytics
     * desativado (template OPERATOR_ANALYTICS da Central). O Super Admin
     * decide se envia — nada sai sozinho. Vai para o telemóvel da conta
     * do operador.
     */
    public function sendSubscriptionNotice(Request $request, Station $station): JsonResponse
    {
        abort_unless($request->user()->hasSuperPowers(), 403, 'Só o Super Admin pode enviar este aviso.');
        $this->assertProvinceAccess($request, $station);

        // Vários operadores (Outubro 2026): o aviso vai a todos os que têm telemóvel.
        $phones = $station->operators()->whereNotNull('phone')->pluck('phone')->filter()->unique()->values();
        if ($phones->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Este posto não tem um operador com telemóvel associado.'], 422);
        }

        $dispatch = null;
        $sent = false;
        foreach ($phones as $phone) {
            $d = $this->subscriptionSms->send(
                $request->user(),
                'OPERATOR_ANALYTICS',
                $phone,
                $this->subscriptions->templateContext(null, $station),
            );

            if ($d === null) {
                continue;
            }

            $ok = $d->status === SmsDispatchStatus::SENT;
            $this->audit($request, $ok ? 'station.subscription_notice_sent' : 'station.subscription_notice_failed', $station->id, null, ['recipient' => $phone]);
            if ($ok || $dispatch === null) {
                $dispatch = $d;
            }
            $sent = $sent || $ok;
        }

        if ($dispatch === null) {
            return response()->json([
                'success' => false,
                'message' => 'Não foi possível enviar: o template "Aviso ao operador" está desativado ou o limite diário de SMS foi atingido.',
            ], 422);
        }

        return response()->json(['success' => $sent, 'data' => $dispatch], $sent ? 201 : 422);
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
