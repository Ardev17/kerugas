<?php

namespace App\Services\Campaigns;

use App\Enums\StationDataAccessEffect;
use App\Jobs\NotifyMunicipalityReopenedJob;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos que saem NA HORA de uma decisão de administrador (Novembro 2026):
 * acesso concedido/retirado a uma pessoa, e o bloqueio de um município
 * mudar. "Melhor esforço": uma falha aqui nunca desfaz a decisão, que já
 * ficou gravada.
 */
class PaywallNotifier
{
    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    /**
     * @param  ?string  $before  efeito anterior (ALLOW/DENY) ou null
     * @param  ?StationDataAccessEffect  $after  efeito novo ou null (INHERIT)
     */
    public function userAccessChanged(User $user, ?string $before, ?StationDataAccessEffect $after, ?\DateTimeInterface $expiresAt): void
    {
        try {
            $stamp = now()->format('YmdHis');

            if ($after === StationDataAccessEffect::ALLOW) {
                $validade = $expiresAt
                    ? 'até ' . \Illuminate\Support\Carbon::instance($expiresAt)->setTimezone($this->campaigns->timezone())->format('d/m/Y')
                    : 'sem data limite';
                $this->campaigns->deliver('access_granted', $user, ['validade' => $validade],
                    "access_granted:{$user->id}:{$stamp}", ['ignore_quiet_hours' => true]);
            } elseif ($after === StationDataAccessEffect::DENY || $before === StationDataAccessEffect::ALLOW->value) {
                $this->campaigns->deliver('access_revoked', $user, [],
                    "access_revoked:{$user->id}:{$stamp}", ['ignore_quiet_hours' => true]);
            }
        } catch (Throwable $e) {
            Log::channel('single')->warning('[PaywallNotifier] acesso (não crítico)', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** O município fechou, abriu ou foi agendado: avisa os clientes (se abriu) e os outros administradores. */
    public function municipalityChanged(Municipality $municipality, bool $wasBlocked, User $actor): void
    {
        try {
            $municipality->refresh();
            $nowBlocked = $municipality->station_data_blocked_from !== null;
            $scheduled = $nowBlocked && $municipality->station_data_blocked_from->isFuture();

            if ($wasBlocked && !$nowBlocked) {
                NotifyMunicipalityReopenedJob::dispatch($municipality->id, now()->format('YmdHis'));
            }

            $estado = !$nowBlocked ? 'aberto a todos'
                : ($scheduled
                    ? 'passa a pago a ' . $municipality->station_data_blocked_from->copy()->setTimezone($this->campaigns->timezone())->format('d/m/Y')
                    : 'passou a pago');

            User::query()
                ->whereIn('role', \App\Enums\UserRole::managementRoles())
                ->where('status', 'ACTIVE')->where('id', '!=', $actor->id)
                ->get()
                ->filter(fn (User $u) => $u->canAccessProvince($municipality->province_id))
                ->each(fn (User $admin) => $this->campaigns->deliver('admin_paywall_changed', $admin, [
                    'quem' => $actor->name, 'municipio' => $municipality->name, 'estado' => $estado,
                ], "admin_paywall_changed:{$admin->id}:{$municipality->id}:" . now()->format('YmdHis'), ['ignore_quiet_hours' => true]));
        } catch (Throwable $e) {
            Log::channel('single')->warning('[PaywallNotifier] município (não crítico)', ['municipality_id' => $municipality->id, 'error' => $e->getMessage()]);
        }
    }
}
