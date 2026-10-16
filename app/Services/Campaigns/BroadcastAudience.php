<?php

namespace App\Services\Campaigns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Públicos possíveis de um envio manual do Super Admin (Setembro 2026),
 * com filtro opcional por província e município.
 */
final class BroadcastAudience
{
    public const OPTIONS = [
        'users' => 'Clientes',
        'drivers' => 'Condutores',
        'operators' => 'Operadores de posto',
        'admins' => 'Admins e Super Admins',
        'all' => 'Todos',
    ];

    public static function query(string $audience, ?int $provinceId = null, ?int $municipalityId = null): Builder
    {
        $roles = match ($audience) {
            'users' => [UserRole::USER],
            'drivers' => [UserRole::DRIVER],
            'operators' => [UserRole::STATION_OPERATOR],
            'admins' => [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN],
            'all' => null,
            default => throw new InvalidArgumentException("Público desconhecido: {$audience}"),
        };

        return User::query()
            ->where('status', 'ACTIVE')
            ->when($roles, fn (Builder $q) => $q->whereIn('role', $roles))
            ->when($provinceId, fn (Builder $q) => $q->where('province_id', $provinceId))
            ->when($municipalityId, fn (Builder $q) => $q->where('municipality_id', $municipalityId));
    }
}
