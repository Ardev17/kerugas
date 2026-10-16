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
    private const PRIVILEGED_ROLES = [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::DRIVER];

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['province', 'municipality']);

        if ($this->isProvinceScopedAdmin($request)) {
            $query->where('province_id', $this->adminProvinceId($request));
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $users = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
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

        if ($actor->role !== UserRole::SUPER_ADMIN && in_array($role, self::PRIVILEGED_ROLES, true)) {
            abort(403, 'Só um Super Admin pode criar uma conta de Admin/Super Admin/Condutor.');
        }

        if ($this->isProvinceScopedAdmin($request) && (int) ($data['province_id'] ?? 0) !== $this->adminProvinceId($request)) {
            abort(403, 'Só pode criar utilizadores na sua própria província.');
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

        if ($actor->role !== UserRole::SUPER_ADMIN) {
            // Um ADMIN nunca pode tocar em papéis privilegiados — nem
            // promover alguém para lá, nem alterar quem já lá está.
            if (in_array($user->role, self::PRIVILEGED_ROLES, true)) {
                abort(403, 'Só um Super Admin pode alterar contas de Admin/Super Admin.');
            }

            if (isset($data['role']) && in_array(UserRole::from($data['role']), self::PRIVILEGED_ROLES, true)) {
                abort(403, 'Só um Super Admin pode promover um utilizador a Admin/Super Admin.');
            }
        }

        $oldValues = $user->only(['role', 'status', 'province_id']);

        // Consistência bidirecional com o formulário de postos: se este
        // utilizador deixa de ser STATION_OPERATOR aqui (em vez de via
        // /admin/stations), o posto que ele operava fica sem operador —
        // nunca um posto "órfão" a apontar para alguém sem o papel certo.
        if ($user->role === UserRole::STATION_OPERATOR
            && isset($data['role'])
            && $data['role'] !== UserRole::STATION_OPERATOR->value) {
            $user->operatedStation?->update(['operator_id' => null]);
        }

        $user->update($data);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'user.updated',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => $oldValues,
            'new_values' => $user->only(['role', 'status', 'province_id']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => new UserResource($user)]);
    }

    private function assertProvinceAccess(Request $request, User $user): void
    {
        if (!$this->isProvinceScopedAdmin($request)) {
            return; // SUPER_ADMIN não tem restrição.
        }

        if ((int) $user->province_id !== $this->adminProvinceId($request)) {
            abort(403, 'Este utilizador pertence a outra província — fora do âmbito da sua conta de administrador.');
        }
    }
}
