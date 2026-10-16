<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserAdminRequest;
use App\Http\Requests\Admin\UpdateUserAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use App\Support\LocationGroups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Gestão administrativa de utilizadores (secção 38). Nunca permite que
 * um admin se autodesprivilegie por engano nem se bloqueie a si próprio
 * (regra de segurança operacional simples, evita lockout acidental).
 *
 * DIFERENÇA REAL entre ADMIN e SUPER_ADMIN (pedido explícito do
 * cliente): um ADMIN nunca pode promover ninguém a ADMIN/SUPER_ADMIN,
 * nem alterar o papel de alguém que já seja ADMIN/SUPER_ADMIN — só o
 * SUPER_ADMIN gere quem tem acesso administrativo. Sem esta regra,
 * "Admin" e "Super Admin" eram apenas rótulos diferentes sem
 * consequência prática nenhuma.
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente, revisto em
 * Setembro 2026 — inicialmente esta área tinha ficado de fora): um
 * ADMIN só vê, edita, bloqueia ou promove utilizadores DA SUA própria
 * província. Só o SUPER_ADMIN continua a gerir utilizadores de
 * qualquer província. `UpdateUserAdminRequest` complementa esta regra
 * impedindo um ADMIN de sequer tentar mudar a província de alguém
 * (mover um utilizador para dentro/fora do seu âmbito seria uma forma
 * indireta de contornar esta restrição).
 */
class UserAdminController extends Controller
{
    use ScopesToAdminProvince;

    // Condutor (Setembro 2026) — deliberadamente incluído aqui, embora
    // não seja um papel "administrativo": a criação/gestão de contas de
    // Condutor foi pedida explicitamente pelo cliente como
    // estritamente SUPER_ADMIN, a mesma exceção à regra "Admin gere a
    // sua própria província" que já se aplicava a ADMIN/SUPER_ADMIN.
    private const PRIVILEGED_ROLES = [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::DRIVER];

    /**
     * Super Admin Provincial (Outubro 2026): gere Admins e Condutores da sua
     * província como um Super Admin, mas NUNCA contas de Super Admin (geral
     * ou provincial) — essas são só do Super Admin geral.
     */
    private const SUPER_ONLY_ROLES = [UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN];

    /** Papéis cujo grupo desce até ao município (os restantes ficam só por província). */
    private const GROUP_BY_MUNICIPALITY = [UserRole::STATION_OPERATOR, UserRole::DRIVER, UserRole::USER];

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery($request, withRole: true)->with(['province', 'municipality']);
        LocationGroups::filterColumns($query, $request);

        // Dentro de um grupo (categoria/província), ordem alfabética.
        // `id` desempata nomes iguais — sem isto, "Mostrar mais" podia repetir ou saltar pessoas.
        $users = ($request->filled('role') ? $query->orderBy('name')->orderBy('id') : $query->latest()->latest('id'))->paginate(20);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    /**
     * Organização por categoria → província → município (Outubro 2026):
     * contagens para as abas e para as secções do painel. Mesma consulta e
     * mesmo âmbito da lista.
     */
    public function groups(Request $request): JsonResponse
    {
        $counts = $this->listQuery($request, withRole: false)->reorder()
            ->selectRaw('role, COUNT(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');

        $tabs = collect(UserRole::cases())->map(fn (UserRole $r) => [
            'key' => $r->value,
            'count' => (int) ($counts[$r->value] ?? 0),
        ])->values();

        $roleParam = $request->query('role');
        $role = is_string($roleParam) ? UserRole::tryFrom($roleParam) : null;
        $groups = $role
            ? LocationGroups::byColumns($this->listQuery($request, withRole: true), 'province_id', in_array($role, self::GROUP_BY_MUNICIPALITY, true) ? 'municipality_id' : null)
            : [];

        return response()->json(['success' => true, 'data' => ['tabs' => $tabs, 'groups' => $groups]]);
    }

    private function listQuery(Request $request, bool $withRole): Builder
    {
        $query = User::query();

        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        if ($withRole && is_string($role = $request->query('role')) && $role !== '') {
            $query->where('role', $role);
        }

        return $query;
    }

    /**
     * Criação administrativa de contas (pedido explícito do cliente,
     * Setembro 2026) — introduzida especificamente para contas de
     * Condutor, que não passam pelo auto-registo público
     * (`/auth/register`, que só cria papel USER). Reaproveita a mesma
     * regra de `update()`: só o SUPER_ADMIN pode criar um papel
     * privilegiado (ADMIN/SUPER_ADMIN/DRIVER); um ADMIN comum continua
     * a só poder criar contas comuns (USER/MODERATOR/STATION_OPERATOR),
     * sempre dentro da sua própria província.
     */
    public function store(StoreUserAdminRequest $request, PhoneNumberService $phoneNumberService): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $role = UserRole::from($data['role']);

        // Investidor (Outubro 2026): só se cria associando a um município, na página Investidores.
        if ($role === UserRole::INVESTOR) {
            abort(422, 'Para criar um investidor, crie a conta como Utilizador e associe-a a um município na página Investidores (só o Super Admin geral).');
        }

        if (!$actor->hasSuperPowers() && in_array($role, self::PRIVILEGED_ROLES, true)) {
            abort(403, 'Só um Super Admin pode criar uma conta de Admin/Super Admin/Condutor.');
        }

        if ($actor->isProvinceSuperAdmin() && in_array($role, self::SUPER_ONLY_ROLES, true)) {
            abort(403, 'Só o Super Admin geral pode criar contas de Super Admin.');
        }

        if ($this->isProvinceScopedAdmin($request) && (int) ($data['province_id'] ?? 0) !== $this->adminProvinceId($request)) {
            abort(403, 'Só pode criar utilizadores na sua própria província.');
        }

        // Criar uma conta já com papel de Moderador/Operador é uma promoção:
        // exige a permissão concedida pelo Super Admin (Outubro 2026).
        if ($role !== UserRole::USER) {
            $this->assertMayChangeRoles($actor);
        }

        // BUG CORRIGIDO (Setembro 2026): o login (AuthController::login)
        // normaliza sempre o número antes de comparar com `users.phone`
        // (mesmo formato canónico usado no auto-registo). Sem normalizar
        // aqui também, uma conta criada como "955555555" ficava
        // gravada literalmente assim, e o login (que procura por
        // "+244955555555") nunca a encontrava — 401 permanente, mesmo
        // com a password certa.
        $normalizedPhone = $phoneNumberService->normalize($data['phone']);

        if ($normalizedPhone === null) {
            abort(422, 'Número de telefone inválido — use um formato angolano válido (ex.: 923123456).');
        }

        if (User::where('phone', $normalizedPhone)->exists()) {
            abort(422, 'Já existe uma conta com este número de telefone.');
        }

        $user = User::create([
            'name' => $data['name'],
            'phone' => $normalizedPhone,
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $role,
            'status' => 'ACTIVE',
            'province_id' => $data['province_id'] ?? null,
            'municipality_id' => $data['municipality_id'] ?? null,
        ]);

        // PIN de SMS do condutor: guardado cifrado e PROVISÓRIO até o condutor o mudar na app.
        if ($role === UserRole::DRIVER && !empty($data['sms_pin'])) {
            $user->forceFill(['sms_pin_hash' => Hash::make($data['sms_pin']), 'sms_pin_encrypted' => $data['sms_pin'], 'sms_pin_temporary' => true])->save();
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'user.created',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => null,
            'new_values' => $user->only(['role', 'status', 'province_id', 'municipality_id']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => new UserResource($user)], 201);
    }

    public function update(UpdateUserAdminRequest $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Não pode alterar o seu próprio papel/estado por esta via.');

        $this->assertProvinceAccess($request, $user);

        $actor = $request->user();
        $data = $request->validated();

        // Investidores (Outubro 2026): só o Super Admin geral mexe nestas contas,
        // e só se passa a Investidor associando um município (página Investidores).
        if ($user->role === UserRole::INVESTOR && !$actor->isGlobalSuperAdmin()) {
            abort(403, 'Só o Super Admin geral gere contas de investidor.');
        }
        if (isset($data['role']) && $data['role'] === UserRole::INVESTOR->value && $user->role !== UserRole::INVESTOR) {
            abort(422, 'Para tornar alguém investidor, associe-o a um município na página Investidores.');
        }

        if ($actor->isProvinceSuperAdmin()) {
            // Super Admin Provincial: nunca toca em contas de Super Admin
            // (nem de outro Provincial), nem promove ninguém a Super Admin.
            if (in_array($user->role, self::SUPER_ONLY_ROLES, true)) {
                abort(403, 'Só o Super Admin geral pode alterar contas de Super Admin.');
            }

            if (isset($data['role']) && in_array(UserRole::from($data['role']), self::SUPER_ONLY_ROLES, true)) {
                abort(403, 'Só o Super Admin geral pode promover um utilizador a Super Admin.');
            }
        } elseif (!$actor->isGlobalSuperAdmin()) {
            // Um ADMIN nunca pode tocar em papéis privilegiados — nem
            // promover alguém para lá, nem alterar quem já lá está.
            if (in_array($user->role, self::PRIVILEGED_ROLES, true)) {
                abort(403, 'Só um Super Admin pode alterar contas de Admin/Super Admin.');
            }

            if (isset($data['role']) && in_array(UserRole::from($data['role']), self::PRIVILEGED_ROLES, true)) {
                abort(403, 'Só um Super Admin pode promover um utilizador a Admin/Super Admin.');
            }

            // Mudar o papel (ex.: Utilizador -> Moderador/Operador) só com a
            // permissão que o Super Admin concede a cada Admin (Outubro 2026).
            // Reenviar o papel que a conta já tem não conta como mudança.
            if (isset($data['role']) && $data['role'] !== $user->role->value) {
                $this->assertMayChangeRoles($actor);
            }
        }

        // Deixou de ser Admin: a permissão de promover não faz sentido noutro papel.
        if (isset($data['role']) && $data['role'] !== UserRole::ADMIN->value) {
            $data['can_promote_users'] = false;
        }

        $oldValues = $user->only(['role', 'status', 'province_id', 'can_promote_users']);

        // Consistência bidirecional com o formulário de postos: se este
        // utilizador deixa de ser STATION_OPERATOR aqui (em vez de via
        // /admin/stations), o posto que ele operava fica sem operador —
        // nunca um posto "órfão" a apontar para alguém sem o papel certo.
        if ($user->role === UserRole::STATION_OPERATOR
            && isset($data['role'])
            && $data['role'] !== UserRole::STATION_OPERATOR->value) {
            $data['operated_station_id'] = null;
        }

        // Deixou de ser investidor: termina todas as associações (deixa de ver a faturação).
        if ($user->role === UserRole::INVESTOR && isset($data['role']) && $data['role'] !== UserRole::INVESTOR->value) {
            app(\App\Services\Investors\InvestorService::class)->endAll($user, $actor, 'role_changed');
            $data['investor_deactivated_at'] = null;
        }

        $user->update($data);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'user.updated',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => $oldValues,
            'new_values' => $user->only(['role', 'status', 'province_id', 'can_promote_users']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => new UserResource($user)]);
    }

    private function assertMayChangeRoles(User $actor): void
    {
        if ($actor->hasSuperPowers()) {
            return;
        }

        abort_unless(
            $actor->can_promote_users,
            403,
            'A sua conta de Admin não tem permissão para promover utilizadores — peça ao Super Admin para a ativar.'
        );
    }

    private function assertProvinceAccess(Request $request, User $user): void
    {
        if (!$this->isProvinceScopedAdmin($request)) {
            return; // SUPER_ADMIN geral não tem restrição.
        }

        if ((int) $user->province_id !== $this->adminProvinceId($request)) {
            abort(403, 'Este utilizador pertence a outra província — fora do âmbito da sua conta de administrador.');
        }
    }
}
