<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem deve ser avisado de um pedido do lado da equipa (Outubro 2026).
 *
 * Um ADMIN só gere a SUA província (ver ScopesToAdminProvince): não faz
 * sentido — nem é aceitável, por privacidade — acordá-lo com o pedido de
 * outra província. O SUPER_ADMIN vê tudo e recebe tudo. Um ADMIN sem
 * província atribuída não recebe nada (igual ao "sem âmbito = sem acesso"
 * do resto da aplicação).
 */
class OrderAudience
{
    /** @return Collection<int, User> */
    public function admins(GasOrder $order): Collection
    {
        return User::query()
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($order) {
                $query->where('role', UserRole::SUPER_ADMIN)
                    ->orWhere(function ($q) use ($order) {
                        // Admin e Super Admin Provincial da província do pedido.
                        $q->whereIn('role', [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN])
                            ->whereNotNull('province_id')
                            ->where('province_id', $order->province_id);
                    });
            })
            ->get();
    }
}
