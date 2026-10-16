<?php

namespace App\Services\Stock;

use App\Enums\NotificationCategory;
use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\Station;
use App\Models\StationCounterReset;
use App\Models\User;
use App\Support\SuperAdminAudience;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Carregamentos de garrafas nos postos + contador do posto (Outubro 2026).
 *
 * - O condutor regista (também offline: a app guarda numa fila e envia
 *   quando houver rede; `client_uuid` impede duplicados).
 * - O operador do posto confirma (se tiver internet) ou contesta. Sem
 *   confirmação o carregamento fica "só condutor" e conta na mesma.
 * - Nunca se apaga nem se reduz a quantidade. O valor pode ser corrigido
 *   por Admin/Super Admin até estar pago ao posto (auditado).
 * - Contador do posto = garrafas chegadas ao servidor desde o último
 *   reinício manual do Super Admin (histórico em station_counter_resets).
 */
class PickupService
{
    public function timezone(): string
    {
        return config('kerugas.notifications.timezone', 'Africa/Luanda');
    }

    /** @return array{0: BottlePickup, 1: bool} [carregamento, criado agora?] */
    public function register(User $driver, array $data): array
    {
        $existing = BottlePickup::where('client_uuid', $data['client_uuid'])->first();
        if ($existing) {
            abort_unless((int) $existing->driver_id === (int) $driver->id, 409, 'Este registo pertence a outro condutor.');

            return [$existing->load(['station', 'driver', 'confirmer']), false];
        }

        $station = Station::find($data['station_id']);
        abort_unless($station && $station->status === StationStatus::ACTIVE, 422, 'Este posto não está ativo.');

        $registeredAt = isset($data['registered_at'])
            ? Carbon::parse($data['registered_at'])->setTimezone(config('app.timezone'))->min(now())
            : now();

        $pickup = BottlePickup::create([
            'client_uuid' => $data['client_uuid'],
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'quantity' => (int) $data['quantity'],
            'amount' => round((float) $data['amount'], 2),
            'registered_at' => $registeredAt,
            'synced_offline' => (bool) ($data['offline'] ?? false),
            // Só o código (SMS) escolhe a origem; a API do condutor nunca passa `source`.
            'source' => $data['source'] ?? BottlePickup::SOURCE_APP,
            'registered_by' => $data['registered_by'] ?? null,
            'needs_confirmation' => (bool) ($data['needs_confirmation'] ?? false),
        ]);

        // Se o condutor já regista pela app, o telemóvel voltou a funcionar:
        // o registo pela central desliga-se sozinho.
        if (($data['source'] ?? BottlePickup::SOURCE_APP) === BottlePickup::SOURCE_APP && $driver->centralPickupActive()) {
            app(CentralPickupService::class)->autoDisable($driver);
        }

        foreach ($station->operatorIds() as $operatorId) {
            $this->notify($operatorId, 'Confirme as garrafas levadas',
                "O condutor {$driver->name} registou que levou {$pickup->quantity} garrafa(s) do posto {$station->name}. Confirme no painel do posto.",
                '/operator');
        }

        return [$pickup->load(['station', 'driver', 'confirmer']), true];
    }

    public function confirm(User $operator, BottlePickup $pickup): BottlePickup
    {
        $this->assertOperatorOf($operator, $pickup);
        abort_if($pickup->confirmed_at || $pickup->disputed_at, 422, 'Este carregamento já foi confirmado ou contestado.');

        $pickup->update(['confirmed_at' => now(), 'confirmed_by' => $operator->id]);

        $this->notify($pickup->driver_id, 'Carregamento confirmado',
            "O posto {$pickup->station->name} confirmou as {$pickup->quantity} garrafa(s).", '/driver');

        return $pickup->fresh(['station', 'driver', 'confirmer']);
    }

    public function dispute(User $operator, BottlePickup $pickup, string $note): BottlePickup
    {
        $this->assertOperatorOf($operator, $pickup);
        abort_if($pickup->confirmed_at || $pickup->disputed_at, 422, 'Este carregamento já foi confirmado ou contestado.');

        $pickup->update(['disputed_at' => now(), 'disputed_by' => $operator->id, 'dispute_note' => $note]);

        $body = "O posto {$pickup->station->name} contestou o carregamento de {$pickup->quantity} garrafa(s) de " . ($pickup->driver?->name ?? 'Conta eliminada') . ": {$note}";
        $this->notify($pickup->driver_id, 'Carregamento contestado', $body, '/driver');
        SuperAdminAudience::ids($pickup->driver?->province_id, $operator->id)
            ->each(fn ($id) => $this->notify($id, 'Carregamento contestado', $body, '/admin/pickups'));

        return $pickup->fresh(['station', 'driver', 'confirmer']);
    }

    public function correctAmount(Request $request, BottlePickup $pickup, float $amount): BottlePickup
    {
        $actor = $request->user();
        $this->assertAdminScope($actor, $pickup);
        abort_if($pickup->station_paid_at !== null, 422, 'Este carregamento já foi pago ao posto — desfaça o pagamento antes de corrigir.');

        $old = (float) $pickup->amount;
        $pickup->update(['amount' => round($amount, 2)]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'pickup.amount_corrected',
            'entity_type' => BottlePickup::class,
            'entity_id' => $pickup->id,
            'old_values' => ['amount' => $old],
            'new_values' => ['amount' => round($amount, 2)],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        SuperAdminAudience::ids($pickup->driver?->province_id, $actor->id)->each(fn ($id) => $this->notify($id, 'Valor de carregamento corrigido',
            "{$actor->name} corrigiu o valor do carregamento #{$pickup->id} ({$pickup->station->name}) de "
            . number_format($old, 2, ',', '.') . ' para ' . number_format($amount, 2, ',', '.') . ' Kz.', '/admin/pickups'));

        return $pickup->fresh(['station', 'driver', 'confirmer']);
    }

    /** Carregamentos visíveis: ADMIN e Super Admin Provincial = condutores da sua província. */
    public function scoped(User $actor): Builder
    {
        $q = BottlePickup::query();

        if (($province = $actor->scopedProvinceId()) !== null) {
            $q->whereIn('driver_id', User::where('role', UserRole::DRIVER)->where('province_id', $province)->select('id'));
        }

        return $q;
    }

    public function lastReset(int $stationId): ?StationCounterReset
    {
        return StationCounterReset::where('station_id', $stationId)->latest('reset_at')->latest('id')->first();
    }

    /** Garrafas chegadas desde o último reinício manual (por posto). */
    public function counters(Collection $stationIds): array
    {
        $out = [];
        $startOfToday = Carbon::now($this->timezone())->startOfDay()->setTimezone(config('app.timezone'));

        foreach ($stationIds->unique() as $id) {
            $reset = $this->lastReset((int) $id);
            $since = BottlePickup::where('station_id', $id)
                ->when($reset, fn ($q) => $q->where('created_at', '>', $reset->reset_at));

            $out[(int) $id] = [
                'since_reset' => (int) (clone $since)->sum('quantity'),
                'pending_confirmation' => (clone $since)->whereNull('confirmed_at')->whereNull('disputed_at')->count(),
                'today' => (int) BottlePickup::where('station_id', $id)->where('registered_at', '>=', $startOfToday)->sum('quantity'),
                'last_reset_at' => $reset?->reset_at?->toIso8601String(),
                'last_reset_bottles' => $reset?->bottles_before,
            ];
        }

        return $out;
    }

    /** Reinício manual do contador (só Super Admin — verificado no controller). */
    public function reset(Request $request, Station $station): StationCounterReset
    {
        return DB::transaction(function () use ($request, $station) {
            Station::whereKey($station->id)->lockForUpdate()->first();
            $before = $this->counters(collect([$station->id]))[$station->id]['since_reset'];

            $reset = StationCounterReset::create([
                'station_id' => $station->id,
                'reset_by' => $request->user()->id,
                'bottles_before' => $before,
                'reset_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'station.counter_reset',
                'entity_type' => Station::class,
                'entity_id' => $station->id,
                'old_values' => ['since_reset' => $before],
                'new_values' => ['since_reset' => 0],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $reset;
        });
    }

    private function assertOperatorOf(User $operator, BottlePickup $pickup): void
    {
        abort_unless($operator->role === UserRole::STATION_OPERATOR, 403, 'Apenas o operador do posto pode confirmar.');
        abort_unless($operator->operated_station_id !== null && (int) $pickup->station_id === (int) $operator->operated_station_id, 403, 'Este carregamento é de outro posto.');
    }

    private function assertAdminScope(User $actor, BottlePickup $pickup): void
    {
        abort_unless($actor->isAdmin(), 403);
        abort_unless($actor->canAccessProvince($pickup->driver?->province_id), 403, 'Este carregamento é de um condutor de outra província.');
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
