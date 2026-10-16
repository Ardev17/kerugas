<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\User;

/**
 * Regras de acesso a pedidos (secção 215):
 * - Utilizador comum: apenas os seus próprios pedidos.
 * - Admin: acesso global.
 * - Operador: apenas pedidos autorizados (regra futura, conforme
 *   configuração de posto atribuído).
 */
class GasOrderPolicy
{
    public function view(User $user, GasOrder $order): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $order->user_id === $user->id;
    }

    public function cancel(User $user, GasOrder $order): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $order->user_id === $user->id
            && in_array($order->status->value, ['PENDING', 'RECEIVED'], true);
    }

    public function assign(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN], true);
    }
}
