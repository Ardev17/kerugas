<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BottlePickup;
use App\Models\User;
use App\Services\Sms\SmsPickupService;
use App\Services\Stock\CentralPickupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Registo de carregamentos pela central (Outubro 2026) — ver CentralPickupService. */
class CentralPickupController extends Controller
{
    public function __construct(private readonly CentralPickupService $central)
    {
    }

    /** Condutores do âmbito de quem pede, com o estado do interruptor. */
    public function drivers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $actor = $request->user();

        $drivers = User::query()->where('role', UserRole::DRIVER)->where('status', 'ACTIVE')
            ->when($actor->scopedProvinceId() !== null, fn ($q) => $q->where('province_id', $actor->scopedProvinceId()))
            ->when($request->boolean('only_enabled'), fn ($q) => $q->whereNotNull('central_pickup_enabled_at')->where(fn ($w) => $w->whereNull('central_pickup_expires_at')->orWhere('central_pickup_expires_at', '>', now())))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->with('province:id,name')->orderBy('name')->limit(60)->get();

        $enablers = User::whereIn('id', $drivers->pluck('central_pickup_enabled_by')->filter())->pluck('name', 'id');

        return response()->json(['success' => true, 'data' => $drivers->map(fn (User $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'phone' => $d->phone,
            'province' => $d->province?->name,
            'central_active' => $d->centralPickupActive(),
            'central_expires_at' => $d->centralPickupActive() ? $d->central_pickup_expires_at?->toIso8601String() : null,
            'central_reason' => $d->centralPickupActive() ? $d->central_pickup_reason : null,
            'central_enabled_by' => $d->centralPickupActive() ? ($enablers[$d->central_pickup_enabled_by] ?? null) : null,
            'sms_pin_set' => $d->sms_pin_hash !== null,
            'sms_pin_temporary' => $d->sms_pin_hash !== null && (bool) $d->sms_pin_temporary,
        ])->values()]);
    }

    public function setSwitch(Request $request, User $driver): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:250'],
            'days' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('kerugas.pickups.central_max_days')],
        ]);

        $driver = $this->central->setSwitch($request, $driver, (bool) $data['enabled'], $data['reason'] ?? null, $data['days'] ?? null);

        return response()->json(['success' => true, 'data' => [
            'id' => $driver->id,
            'central_active' => $driver->centralPickupActive(),
            'central_expires_at' => $driver->central_pickup_expires_at?->toIso8601String(),
        ]]);
    }

    /** O condutor esqueceu o PIN: o Super Admin define um novo, PROVISÓRIO (o condutor muda-o na app). */
    public function resetSmsPin(Request $request, User $driver): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasSuperPowers(), 403);
        abort_unless($driver->role === UserRole::DRIVER && $actor->canAccessProvince($driver->province_id), 403, 'Este condutor é de outra província.');

        $data = $request->validate(['pin' => ['required', 'string']]);
        if ($problem = \App\Support\SmsPin::problem($data['pin'])) {
            abort(422, $problem);
        }

        $driver->forceFill(['sms_pin_hash' => \Illuminate\Support\Facades\Hash::make($data['pin']), 'sms_pin_encrypted' => $data['pin'], 'sms_pin_temporary' => true, 'sms_pin_failures' => 0, 'sms_pin_locked_until' => null])->save();
        \App\Models\AuditLog::create([
            'user_id' => $actor->id, 'action' => 'driver.sms_pin_reset', 'entity_type' => User::class, 'entity_id' => $driver->id,
            'old_values' => null, 'new_values' => null, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => ['pin_set' => true, 'temporary' => true]]);
    }

    /**
     * Ver o PIN de carregamento do condutor (Outubro 2026): só Super Admin
     * Geral e Provincial (da província do condutor). Cada consulta fica
     * registada na auditoria.
     */
    public function showSmsPin(Request $request, User $driver): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasSuperPowers(), 403);
        abort_unless($driver->role === UserRole::DRIVER && $actor->canAccessProvince($driver->province_id), 403, 'Este condutor é de outra província.');

        try {
            $pin = $driver->sms_pin_hash ? $driver->sms_pin_encrypted : null;
        } catch (\Throwable) {
            $pin = null; // cópia ilegível (ex.: APP_KEY mudou) — redefinir resolve
        }

        \App\Models\AuditLog::create([
            'user_id' => $actor->id, 'action' => 'driver.sms_pin_viewed', 'entity_type' => User::class, 'entity_id' => $driver->id,
            'old_values' => null, 'new_values' => null, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => [
            'pin' => $pin,
            'pin_set' => $driver->sms_pin_hash !== null,
            'temporary' => $driver->sms_pin_hash !== null && (bool) $driver->sms_pin_temporary,
            'message' => $driver->sms_pin_hash === null
                ? 'Este condutor ainda não tem PIN de carregamento.'
                : ($pin === null ? 'Este PIN foi definido antes de a central o poder ver. Defina um novo PIN para o passar a ver.' : null),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'driver_id' => ['required', 'integer'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'picked_at' => ['nullable', 'date'],
            'channel' => ['required', Rule::in(BottlePickup::CHANNELS)],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $pickup = $this->central->register($request, $data);

        return response()->json(['success' => true, 'data' => $pickup->toApi()], 201);
    }

    /** O SMS "CARREGAR …" caiu no telemóvel da central: quem atende cola o texto aqui. */
    public function storeFromSms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sender_phone' => ['required', 'string', 'max:20'],
            'sms_text' => ['required', 'string', 'max:160'],
            'received_at' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        return response()->json(['success' => true, 'data' => app(SmsPickupService::class)->register($request, $data)->toApi()], 201);
    }

    public function confirm(Request $request, BottlePickup $pickup): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->central->confirmByAdmin($request->user(), $pickup)->toApi()]);
    }
}
