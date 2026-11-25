<?php

namespace App\Services\StaffChat;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Quem entra no Chat da Equipa (Outubro 2026): os admins de todo o país —
 * Super Admin ("Admin Geral"), Super Admin Provincial e Admin. Nem o
 * Moderador nem qualquer outro papel. Todos veem e escrevem na mesma sala;
 * só o Super Admin geral modera (fixar, remover, silenciar, apagar de vez).
 */
class StaffChatAccess
{
    /** @return list<UserRole> */
    public static function roles(): array
    {
        return [UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN];
    }

    /** @return list<string> */
    public static function roleValues(): array
    {
        return array_map(fn (UserRole $r) => $r->value, self::roles());
    }

    public static function allowed(User $user): bool
    {
        return in_array($user->role, self::roles(), true) && $user->status === 'ACTIVE';
    }

    public static function moderates(User $user): bool
    {
        return $user->role === UserRole::SUPER_ADMIN;
    }

    /** Tipo mostrado junto ao nome. */
    public static function typeLabel(UserRole $role): string
    {
        return match ($role) {
            UserRole::SUPER_ADMIN => 'Admin Geral',
            UserRole::PROVINCE_SUPER_ADMIN => 'Admin Provincial',
            default => 'Admin',
        };
    }

    /** Duração máxima de um silenciamento em horas (null = pode ser permanente). */
    public static function maxMuteHours(User $user): ?int
    {
        return self::moderates($user) ? null : 0;
    }
}
