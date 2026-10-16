<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\User;

/**
 * Regras de acesso a pedidos (secção 215):
 * - Utilizador comum: apenas os seus próprios pedidos.
 * - Admin / Super Admin Provincial: só pedidos da sua província
 *   (Outubro 2026); Super Admin geral: todos.
 * - Operador: apenas pedidos autorizados (regra futura, conforme
 *   configuração de posto atribuído).
 */
class GasOrderPolicy
{
    public function view(User $user, GasOrder $order): bool
    {
        if ($user->isAdmin()) {
            return $user->canAccessProvince($order->province_id);
        }

        return $order->user_id === $user->id;
    }

    public function cancel(User $user, GasOrder $order): bool
    {
        if ($user->isAdmin()) {
            // Mesma regra do painel: só na sua província, e um pedido já
            // entregue a um condutor só o cancela o Super Admin (geral ou
            // provincial) — nunca um Admin comum por esta via alternativa.
            return $user->canAccessProvince($order->province_id)
                && ($user->hasSuperPowers() || $order->assignedUser?->role !== UserRole::DRIVER);
        }

        return $order->user_id === $user->id
            && in_array($order->status->value, ['PENDING', 'RECEIVED'], true);
    }

    public function assign(User $user): bool
    {
        return $user->isAdmin();
    }
}
