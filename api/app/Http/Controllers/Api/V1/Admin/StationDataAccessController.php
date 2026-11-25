<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Services\Campaigns\PaywallNotifier;
use App\Enums\StationDataAccessEffect;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\StationDataGrant;
use App\Models\User;
use App\Services\Stations\StationDataAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fechar e abrir a consulta do estado dos postos (Novembro 2026).
 *
 * Dois níveis: o município é a regra geral, a pessoa é a excepção, e a
 * excepção ganha. Só o Super Admin geral e o Super Admin Provincial mexem
 * nisto — e o Provincial só dentro da província dele.
 */
class StationDataAccessController extends Controller
{
    public function __construct(private readonly StationDataAccessService $access)
    {
    }

    // ------------------------------------------------------------ municípios

    public function municipalities(Request $request): JsonResponse
    {
        $municipalities = Municipality::query()
            ->with(['province:id,name', 'stationDataBlockedBy:id,name'])
            ->when($this->provinciaDoActor($request) !== null,
                fn ($q) => $q->where('province_id', $this->provinciaDoActor($request)))
            ->orderBy('province_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $municipalities->map(fn (Municipality $m) => $this->municipioEmJson($m))->values(),
        ]);
    }

    public function setMunicipality(Request $request, Municipality $municipality): JsonResponse
    {
        $this->garanteAmbito($request, $municipality->province_id);

        $data = $request->validate([
            'blocked' => ['required', 'boolean'],
            // Sem data, fecha já. Com data no futuro, fica agendado: é assim
            // que se marca hoje o fim dos dias grátis.
            'from' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $antes = $municipality->station_data_blocked_from?->toIso8601String();
        $estavaFechado = $municipality->station_data_blocked_from !== null;

        $municipality->forceFill([
            'station_data_blocked_from' => $data['blocked'] ? ($data['from'] ?? now()) : null,
            'station_data_blocked_reason' => $data['blocked'] ? ($data['reason'] ?? null) : null,
            'station_data_blocked_by_id' => $data['blocked'] ? $request->user()->id : null,
        ])->save();

        $this->regista($request, $data['blocked'] ? 'station_data.municipality_blocked' : 'station_data.municipality_unblocked',
            Municipality::class, $municipality->id,
            ['blocked_from' => $antes],
            ['blocked_from' => $municipality->station_data_blocked_from?->toIso8601String(), 'reason' => $municipality->station_data_blocked_reason]);

        app(PaywallNotifier::class)->municipalityChanged($municipality, $estavaFechado, $request->user());

        return response()->json([
            'success' => true,
            'data' => $this->municipioEmJson($municipality->fresh(['province', 'stationDataBlockedBy'])),
        ]);
    }

    // --------------------------------------------------------------- pessoas

    public function users(Request $request): JsonResponse
    {
        $procura = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $padrao = '%' . addcslashes($procura, '%_\\') . '%';

        $users = User::query()
            ->whereIn('role', array_map(fn (UserRole $r) => $r->value, StationDataAccessService::GRANTABLE_ROLES))
            ->when($this->provinciaDoActor($request) !== null,
                fn ($q) => $q->where('province_id', $this->provinciaDoActor($request)))
            ->when($procura !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', $padrao)->orWhere('phone', 'like', $padrao)
            ))
            ->with('municipality:id,name')
            ->orderBy('name')
            ->limit(50)
            ->get();

        $grants = StationDataGrant::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return response()->json([
            'success' => true,
            'data' => $users->map(fn (User $u) => $this->pessoaEmJson($u, $grants->get($u->id)))->values(),
        ]);
    }

    public function setUser(Request $request, User $user): JsonResponse
    {
        $this->garanteAmbito($request, $user->province_id);

        // Excepções só para quem a regra do município apanha. Uma conta da
        // equipa vê tudo para trabalhar; um DENY nela seria um administrador a
        // fechar a consulta a outro — um colega, ou o próprio Super Admin.
        abort_unless(in_array($user->role, StationDataAccessService::GRANTABLE_ROLES, true), 422,
            'Só se abrem ou fecham excepções a clientes e ao pessoal dos postos.');

        $data = $request->validate([
            'effect' => ['required', Rule::in(['INHERIT', 'ALLOW', 'DENY'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $antes = StationDataGrant::query()->where('user_id', $user->id)->first()?->effect?->value;

        if ($data['effect'] === 'INHERIT') {
            StationDataGrant::query()->where('user_id', $user->id)->delete();
            $grant = null;
        } else {
            $grant = StationDataGrant::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'effect' => StationDataAccessEffect::from($data['effect']),
                    'expires_at' => $data['expires_at'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'granted_by_id' => $request->user()->id,
                ],
            );
        }

        $this->regista($request, 'station_data.user_access_changed', User::class, $user->id,
            ['effect' => $antes],
            ['effect' => $data['effect'], 'expires_at' => $data['expires_at'] ?? null, 'reason' => $data['reason'] ?? null]);

        app(PaywallNotifier::class)->userAccessChanged($user, $antes, $grant?->effect, $grant?->expires_at);

        return response()->json(['success' => true, 'data' => $this->pessoaEmJson($user->fresh('municipality'), $grant)]);
    }

    // ----------------------------------------------------------------- apoio

    /**
     * Null para o Super Admin geral (vê tudo); o id da província para o
     * Provincial. Um Provincial sem província atribuída recebe 0 — que não
     * corresponde a província nenhuma — e fica sem acesso a nada, em vez de
     * passar por Super Admin geral (auditoria de segurança, Novembro 2026).
     */
    private function provinciaDoActor(Request $request): ?int
    {
        return $request->user()->scopedProvinceId();
    }

    private function garanteAmbito(Request $request, ?int $provinceId): void
    {
        $ambito = $this->provinciaDoActor($request);

        if ($ambito === null) {
            return;
        }

        abort_if($ambito === 0 || $provinceId === null || (int) $provinceId !== $ambito, 403,
            'Só pode mexer no acesso dentro da sua própria província.');
    }

    /** @return array<string, mixed> */
    private function municipioEmJson(Municipality $m): array
    {
        $agendado = $m->station_data_blocked_from !== null && $m->station_data_blocked_from->isFuture();

        return [
            'id' => $m->id,
            'name' => $m->name,
            'province' => $m->province?->name,
            'blocked' => $m->stationDataBlocked(),
            'scheduled' => $agendado,
            'blocked_from' => $m->station_data_blocked_from?->toIso8601String(),
            'reason' => $m->station_data_blocked_reason,
            'blocked_by' => $m->stationDataBlockedBy?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function pessoaEmJson(User $u, ?StationDataGrant $grant): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'municipality' => $u->municipality?->name,
            'role' => $u->role?->value,
            'effect' => $grant?->effect->value ?? 'INHERIT',
            'active' => $grant?->isActive() ?? false,
            'expires_at' => $grant?->expires_at?->toIso8601String(),
            'reason' => $grant?->reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function regista(Request $request, string $action, string $entity, int $id, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
