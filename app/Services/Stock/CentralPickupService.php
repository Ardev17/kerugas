<?php

namespace App\Services\Stock;

use App\Enums\NotificationCategory;
use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\Station;
use App\Models\User;
use App\Support\SuperAdminAudience;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registo de carregamentos pela central (Outubro 2026).
 *
 * Para o condutor que NÃO consegue registar (sem telemóvel, sem rede):
 *  1. o Super Admin ou o Super Admin Provincial LIGA o registo pela central
 *     para esse condutor (com motivo; caduca sozinho; desliga-se quando o
 *     condutor voltar a registar pela app);
 *  2. só então um Admin / Provincial / Super Admin da província do condutor
 *     regista o carregamento, indicando canal e motivo (sem fotografia);
 *  3. o registo conta no stock logo, mas SÓ conta para pagar ao posto depois
 *     de confirmado por: o Super Admin, o condutor ou o operador do posto.
 *
 * Nada se apaga nem se reduz. Tudo fica no registo de auditoria e quem tem
 * de saber é avisado (condutor, posto, Super Admins da província).
 */
class CentralPickupService
{
    public function __construct(private readonly PickupService $pickups)
    {
    }

    // ── Interruptor por condutor ───────────────────────────────────────

    public function setSwitch(Request $request, User $driver, bool $enabled, ?string $reason, ?int $days): User
    {
        $actor = $request->user();
        abort_unless($actor->hasSuperPowers(), 403, 'Só o Super Admin e o Super Admin Provincial ligam o registo pela central.');
        $this->assertDriverInScope($actor, $driver);

        $cfg = config('kerugas.pickups');
        $old = ['active' => $driver->centralPickupActive(), 'expires_at' => $driver->central_pickup_expires_at?->toIso8601String()];

        if ($enabled) {
            $reason = trim((string) $reason);
            abort_if(mb_strlen($reason) < 5, 422, 'Indique o motivo (ex.: o condutor está sem telemóvel).');
            $days = max(1, min((int) ($days ?: $cfg['central_default_days']), (int) $cfg['central_max_days']));

            $driver->forceFill([
                'central_pickup_enabled_at' => now(),
                'central_pickup_expires_at' => now()->addDays($days),
                'central_pickup_enabled_by' => $actor->id,
                'central_pickup_reason' => Str::limit($reason, 250, ''),
            ])->save();

            $this->notify($driver->id, 'Registo pela central ligado',
                "A central pode registar os seus carregamentos nos próximos {$days} dia(s). Se algum registo não for verdadeiro, conteste-o na app.", '/driver');
        } else {
            $this->clearSwitch($driver);
        }

        $this->audit($request, $enabled ? 'driver.central_pickup_enabled' : 'driver.central_pickup_disabled', $driver->id, $old, [
            'active' => $enabled, 'expires_at' => $driver->central_pickup_expires_at?->toIso8601String(), 'reason' => $reason,
        ]);

        return $driver->fresh();
    }

    /** O condutor voltou a registar pela app. */
    public function autoDisable(User $driver): void
    {
        $this->clearSwitch($driver);
        AuditLog::create([
            'user_id' => $driver->id,
            'action' => 'driver.central_pickup_auto_disabled',
            'entity_type' => User::class,
            'entity_id' => $driver->id,
            'old_values' => ['active' => true],
            'new_values' => ['active' => false, 'why' => 'O condutor voltou a registar pela app.'],
        ]);
        SuperAdminAudience::ids($driver->province_id)->each(fn ($id) => $this->notify($id, 'Registo pela central desligado',
            "{$driver->name} voltou a registar pela app — o registo pela central foi desligado.", '/admin/pickups'));
    }

    private function clearSwitch(User $driver): void
    {
        $driver->forceFill([
            'central_pickup_enabled_at' => null, 'central_pickup_expires_at' => null,
            'central_pickup_enabled_by' => null, 'central_pickup_reason' => null,
        ])->save();
    }

    // ── Registar ───────────────────────────────────────────────────────

    public function register(Request $request, array $data): BottlePickup
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403);

        $driver = User::where('role', UserRole::DRIVER)->where('status', 'ACTIVE')->find($data['driver_id']);
        abort_unless($driver, 422, 'Condutor inválido.');
        $this->assertDriverInScope($actor, $driver);
        abort_unless($driver->centralPickupActive(), 422,
            'O registo pela central não está ligado para este condutor. Peça ao Super Admin (ou Provincial) para o ligar.');

        $cfg = config('kerugas.pickups');
        abort_if((int) $data['quantity'] > (int) $cfg['central_max_quantity'], 422, "Máximo de {$cfg['central_max_quantity']} garrafas por registo.");

        $station = Station::find($data['station_id']);
        abort_unless($station && $station->status === StationStatus::ACTIVE, 422, 'Este posto não está ativo.');

        $when = isset($data['picked_at']) ? Carbon::parse($data['picked_at']) : now();
        abort_if($when->isFuture(), 422, 'A data do carregamento não pode estar no futuro.');
        abort_if($when->lt(now()->subHours((int) $cfg['central_max_hours_back'])), 422,
            "Só se registam carregamentos das últimas {$cfg['central_max_hours_back']} horas.");

        $uuid = $data['client_uuid'] ?? (string) Str::uuid();
        if ($existing = BottlePickup::where('client_uuid', $uuid)->first()) {
            return $existing->load(['station', 'driver', 'confirmer', 'registrar']);
        }

        $pickup = DB::transaction(function () use ($data, $driver, $station, $actor, $when, $uuid) {
            $pickup = BottlePickup::create([
                'client_uuid' => $uuid,
                'station_id' => $station->id,
                'driver_id' => $driver->id,
                'quantity' => (int) $data['quantity'],
                'amount' => round((float) $data['amount'], 2),
                'registered_at' => $when,
                'synced_offline' => false,
                'source' => BottlePickup::SOURCE_CENTRAL,
                'registered_by' => $actor->id,
                'central_channel' => $data['channel'],
                'central_reason' => trim($data['reason']),
                'needs_confirmation' => true,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'pickup.central_registered',
                'entity_type' => BottlePickup::class,
                'entity_id' => $pickup->id,
                'old_values' => null,
                'new_values' => $pickup->only(['driver_id', 'station_id', 'quantity', 'amount', 'central_channel', 'central_reason']),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $pickup;
        });

        $this->notifyRegistered($pickup, $driver, $station, $actor);

        return $pickup->load(['station', 'driver', 'confirmer', 'registrar']);
    }

    private function notifyRegistered(BottlePickup $pickup, User $driver, Station $station, User $actor): void
    {
        $what = "{$pickup->quantity} garrafa(s) no posto {$station->name}";
        $this->notify($driver->id, 'Carregamento registado pela central',
            "{$actor->name} registou por si {$what}. Confirme na app — se não é verdade, conteste.", '/driver');

        foreach ($station->operatorIds() as $operatorId) {
            $this->notify($operatorId, 'Confirme as garrafas levadas',
                "A central registou que {$driver->name} levou {$what}. Confirme no painel do posto.", '/operator');
        }

        SuperAdminAudience::ids($driver->province_id, $actor->id)->each(fn ($id) => $this->notify($id, 'Carregamento registado pela central',
            "{$actor->name} registou {$what} para {$driver->name} ({$pickup->central_channel}). Fica por confirmar.", '/admin/pickups'));

        // Muitos registos do mesmo Admin num dia: avisa os Super Admins.
        $limit = (int) config('kerugas.pickups.central_admin_alert_per_day');
        if ($actor->role === UserRole::ADMIN && $limit > 0) {
            $today = BottlePickup::where('registered_by', $actor->id)->where('created_at', '>=', now()->startOfDay())->count();
            if ($today === $limit + 1) {
                SuperAdminAudience::ids($driver->province_id)->each(fn ($id) => $this->notify($id, 'Muitos registos pela central',
                    "{$actor->name} já fez {$today} registos pela central hoje. Vale a pena rever.", '/admin/pickups'));
            }
        }
    }

    // ── Confirmar / contestar ──────────────────────────────────────────

    /** Super Admin (ou Provincial da província) — nunca quem fez o registo. */
    public function confirmByAdmin(User $actor, BottlePickup $pickup): BottlePickup
    {
        abort_unless($actor->hasSuperPowers(), 403, 'Só o Super Admin confirma por esta via.');
        abort_unless($actor->canAccessProvince($pickup->driver?->province_id), 403, 'Este carregamento é de outra província.');
        $this->assertCentral($pickup);
        abort_if((int) $pickup->registered_by === (int) $actor->id, 403, 'Quem fez o registo não o pode confirmar — peça a outro Super Admin, ao condutor ou ao posto.');

        return $this->markConfirmed($pickup, $actor, "O Super Admin {$actor->name} confirmou");
    }

    public function confirmByDriver(User $driver, BottlePickup $pickup): BottlePickup
    {
        abort_unless((int) $pickup->driver_id === (int) $driver->id, 403, 'Este carregamento é de outro condutor.');
        $this->assertCentral($pickup);

        return $this->markConfirmed($pickup, $driver, 'O condutor confirmou');
    }

    public function disputeByDriver(User $driver, BottlePickup $pickup, string $note): BottlePickup
    {
        abort_unless((int) $pickup->driver_id === (int) $driver->id, 403, 'Este carregamento é de outro condutor.');
        $this->assertCentral($pickup);

        $pickup->update(['disputed_at' => now(), 'disputed_by' => $driver->id, 'dispute_note' => $note]);

        $body = "{$driver->name} contestou o carregamento de {$pickup->quantity} garrafa(s) registado pela central ({$pickup->station->name}): {$note}";
        SuperAdminAudience::ids($driver->province_id)->each(fn ($id) => $this->notify($id, 'Carregamento da central contestado', $body, '/admin/pickups'));
        if ($pickup->registered_by) {
            $this->notify($pickup->registered_by, 'Carregamento da central contestado', $body, '/admin/pickups');
        }
        $this->audit(null, 'pickup.central_disputed', $pickup->id, null, ['by' => $driver->id, 'note' => $note], $driver->id);

        return $pickup->fresh(['station', 'driver', 'confirmer', 'registrar']);
    }

    private function markConfirmed(BottlePickup $pickup, User $by, string $who): BottlePickup
    {
        abort_if($pickup->confirmed_at || $pickup->disputed_at, 422, 'Este carregamento já foi confirmado ou contestado.');
        $pickup->update(['confirmed_at' => now(), 'confirmed_by' => $by->id]);
        $this->audit(null, 'pickup.central_confirmed', $pickup->id, null, ['by' => $by->id, 'role' => $by->role->value], $by->id);

        if ($pickup->registered_by && (int) $pickup->registered_by !== (int) $by->id) {
            $this->notify($pickup->registered_by, 'Carregamento confirmado', "{$who} o carregamento de {$pickup->quantity} garrafa(s) ({$pickup->station->name}).", '/admin/pickups');
        }

        return $pickup->fresh(['station', 'driver', 'confirmer', 'registrar']);
    }

    private function assertCentral(BottlePickup $pickup): void
    {
        abort_unless($pickup->needs_confirmation, 422, 'Este carregamento não precisa de confirmação.');
    }

    private function assertDriverInScope(User $actor, User $driver): void
    {
        abort_unless($driver->role === UserRole::DRIVER, 422, 'Este utilizador não é condutor.');
        abort_unless($actor->canAccessProvince($driver->province_id), 403, 'Este condutor é de outra província.');
    }

    private function audit(?Request $request, string $action, int $entityId, ?array $old, array $new, ?int $userId = null): void
    {
        AuditLog::create([
            'user_id' => $userId ?? $request?->user()?->id,
            'action' => $action,
            'entity_type' => str_starts_with($action, 'driver.') ? User::class : BottlePickup::class,
            'entity_id' => $entityId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function notify(int $userId, string $title, string $body, string $path): void
    {
        try {
            AppNotification::create([
                'user_id' => $userId,
                'category' => NotificationCategory::SYSTEM,
                'title' => $title,
                'body' => $body,
                'data' => ['type' => 'pickup', 'path' => $path],
            ]);
        } catch (Throwable) {
            // melhor esforço
        }
    }
}
