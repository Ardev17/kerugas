<?php

namespace App\Http\Controllers\Api\V1\StationManager;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminStationResource;
use App\Models\AuditLog;
use App\Models\Station;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Painel do Admin de Posto / gerente (Novembro 2026, decisão do cliente).
 *
 * O gerente gere SÓ o posto ligado à sua conta (`users.managed_station_id`):
 * - as informações do posto (nome, morada, contacto, horário, localização);
 * - os operadores do posto (promove utilizadores do MESMO município do
 *   posto e retira-os);
 * - os condutores do posto (cria, edita, suspende e retira).
 *
 * O estado do gás (disponível/fila) é atualizado pelas rotas /operator/*,
 * que também aceitam o gerente. O posto nunca vem do pedido: é sempre o da
 * conta, por isso um gerente nunca chega a outro posto.
 *
 * O operador de posto NÃO cria nem promove condutores,só o gerente e os
 * admins (via /admin/users).
 */
class StationManagerController extends Controller
{
    public function __construct(private readonly PhoneNumberService $phones)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        return response()->json(['success' => true, 'data' => $this->present($station)]);
    }

    public function update(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_point' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'opening_hours' => ['sometimes', 'nullable', 'string', 'max:100'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
        ]);

        $old = $station->only(array_keys($data));
        $station->update($data);

        $this->audit($request, 'station.manager_updated', Station::class, $station->id, $old, $station->only(array_keys($data)));

        return response()->json(['success' => true, 'data' => $this->present($station->fresh())]);
    }

    // ───────────────────────── operadores

    /**
     * Promove a operador um utilizador já registado, pelo telemóvel.
     * Regras (Novembro 2026): só utilizadores comuns, sem outro posto, e do
     * MESMO município do posto,sem município, a promoção é bloqueada.
     */
    public function addOperator(Request $request): JsonResponse
    {
        $station = $this->myStation($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        $phone = $this->phones->normalize($data['phone']);
        abort_if($phone === null, 422, 'Número de telefone inválido,use um formato angolano válido (ex.: 923123456).');

        $user = User::where('phone', $phone)->first();
        abort_unless($user, 422, 'Não existe nenhuma conta KeruGás com este número. A pessoa tem de se registar primeiro.');
        abort_if($user->role === UserRole::STATION_OPERATOR && (int) $user->operated_station_id === (int) $station->id, 422, "{$user->name} já é operador deste posto.");
        abort_unless($user->role === UserRole::USER, 422, "{$user->name} já tem outro papel na KeruGás e não pode ser operador de posto.");
        abort_if($user->status !== 'ACTIVE', 422, "A conta de {$user->name} está suspensa.");
        abort_if($user->municipality_id === null, 422, "{$user->name} não tem município no perfil. Só se promove a operador quem é do município do posto.");
        abort_unless((int) $user->municipality_id === (int) $station->municipality_id, 422, "{$user->name} é de outro município. Só pode promover a operador pessoas do município do posto.");

        $user->update(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $station->id, 'province_id' => $station->province_id]);

        $this->audit($request, 'station.operator_added', User::class, $user->id, ['role' => UserRole::USER->value], ['role' => UserRole::STATION_OPERATOR->value, 'operated_station_id' => $station->id]);

        return response()->json(['success' => true, 'data' => $this->present($station->fresh())], 201);
    }

    public function removeOperator(Request $request, User $user): JsonResponse
    {
        $station = $this->myStation($request);
        abort_unless($user->role === UserRole::STATION_OPERATOR && (int) $user->operated_station_id === (int) $station->id, 404, 'Este operador não pertence ao seu posto.');

        $user->update(['role' => UserRole::USER, 'operated_station_id' => null]);

        $this->audit($request, 'station.operator_removed', User::class, $user->id, ['role' => UserRole::STATION_OPERATOR->value, 'operated_station_id' => $station->id], ['role' => UserRole::USER->value]);

        return response()->json(['success' => true, 'data' => $this->present($station->fresh())]);
    }

    // ───────────────────────── condutores do posto

    public function storeDriver(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $phone = $this->phones->normalize($data['phone']);
        abort_if($phone === null, 422, 'Número de telefone inválido,use um formato angolano válido (ex.: 923123456).');
        abort_if(User::where('phone', $phone)->exists(), 422, 'Já existe uma conta com este número de telefone.');

        $driver = User::create([
            'name' => $data['name'],
            'phone' => $phone,
            'password' => Hash::make($data['password']),
            'role' => UserRole::STATION_DRIVER,
            'status' => 'ACTIVE',
            'province_id' => $station->province_id,
            'municipality_id' => $station->municipality_id,
            'driver_station_id' => $station->id,
            'driver_available' => true,
        ]);

        $this->audit($request, 'station.driver_created', User::class, $driver->id, null, $driver->only(['role', 'driver_station_id', 'municipality_id']));

        return response()->json(['success' => true, 'data' => $this->present($station->fresh())], 201);
    }

    public function updateDriver(Request $request, User $driver): JsonResponse
    {
        $station = $this->myStation($request);
        $this->assertOwnDriver($station, $driver);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'status' => ['sometimes', 'in:ACTIVE,SUSPENDED'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:100'],
        ]);

        $old = $driver->only(['name', 'status']);
        $changes = collect($data)->except('password')->all();
        if (isset($data['password'])) {
            $changes['password'] = Hash::make($data['password']);
        }
        $driver->update($changes);

        // Suspenso ou com password nova: termina as sessões abertas.
        if (($data['status'] ?? null) === 'SUSPENDED' || isset($data['password'])) {
            $driver->tokens()->delete();
        }

        $this->audit($request, 'station.driver_updated', User::class, $driver->id, $old, $driver->only(['name', 'status']) + (isset($data['password']) ? ['password' => 'alterada'] : []));

        return response()->json(['success' => true, 'data' => $this->present($station->fresh())]);
    }

    /** Retira o condutor do posto: a conta fica como utilizador comum. */
    public function removeDriver(Request $request, User $driver): JsonResponse
    {
        $station = $this->myStation($request);
        $this->assertOwnDriver($station, $driver);

        DB::transaction(function () use ($driver) {
            $driver->update(['role' => UserRole::USER, 'driver_station_id' => null, 'driver_available' => true]);
            $driver->tokens()->delete();
        });

        $this->audit($request, 'station.driver_removed', User::class, $driver->id, ['role' => UserRole::STATION_DRIVER->value, 'driver_station_id' => $station->id], ['role' => UserRole::USER->value]);

        return response()->json(['success' => true, 'data' => $this->present($station->fresh())]);
    }

    // ───────────────────────── apoio

    private function myStation(Request $request): Station
    {
        $user = $request->user();
        abort_unless($user->role === UserRole::STATION_MANAGER, 403, 'Apenas o Admin de Posto tem acesso a este painel.');

        $station = $user->managedStation;
        abort_if(!$station, 404, 'Não existe posto atribuído à sua conta. Contacte o suporte do KERUGAS.');

        return $station;
    }

    private function assertOwnDriver(Station $station, User $driver): void
    {
        abort_unless($driver->role === UserRole::STATION_DRIVER && (int) $driver->driver_station_id === (int) $station->id, 404, 'Este condutor não pertence ao seu posto.');
    }

    /** @return array<string, mixed> */
    private function present(Station $station): array
    {
        $station->load(['province', 'municipality', 'operators', 'managers', 'stationDrivers']);
        $person = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'status' => $u->status];

        return [
            'station' => (new AdminStationResource($station))->resolve(),
            'operators' => $station->operators->map($person)->values(),
            'managers' => $station->managers->map($person)->values(),
            'drivers' => $station->stationDrivers->map(fn (User $u) => $person($u) + ['available' => (bool) $u->driver_available])->values(),
        ];
    }

    private function audit(Request $request, string $action, string $type, int $id, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
