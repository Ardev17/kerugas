<?php

namespace App\Services\Community;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\User;

/**
 * Quem vê, escreve e modera onde nos Vizinhos KeruGás (Outubro 2026).
 *
 *  - Utilizador, condutor, operador: só o SEU município (ver e escrever).
 *  - Moderador: vê e modera o PAÍS TODO (decisão do cliente); escreve no seu município.
 *  - Admin: vê e modera os municípios da SUA província; escreve no seu município.
 *  - Super Admin Provincial: o mesmo na sua província, e escreve em qualquer
 *    município dela como "Equipa KeruGás".
 *  - Super Admin: tudo, em qualquer município, como "Equipa KeruGás".
 */
class CommunityAccess
{
    /** @var array<int, int|null> */
    private static array $provinceCache = [];

    public static function isStaff(User $user): bool
    {
        return in_array($user->role, self::staffRoles(), true);
    }

    /** @return list<UserRole> */
    public static function staffRoles(): array
    {
        return [UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN, UserRole::MODERATOR];
    }

    public static function provinceOf(int $municipalityId): ?int
    {
        if (!array_key_exists($municipalityId, self::$provinceCache)) {
            self::$provinceCache[$municipalityId] = Municipality::query()->whereKey($municipalityId)->value('province_id');
        }

        return self::$provinceCache[$municipalityId];
    }

    public static function flush(): void
    {
        self::$provinceCache = [];
    }

    public static function canView(User $user, int $municipalityId): bool
    {
        if ($user->role === UserRole::SUPER_ADMIN || $user->role === UserRole::MODERATOR) {
            return self::provinceOf($municipalityId) !== null;
        }

        if (in_array($user->role, [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN], true)) {
            $province = self::provinceOf($municipalityId);

            return $province !== null && $user->canAccessProvince($province);
        }

        return $user->municipality_id !== null && (int) $user->municipality_id === $municipalityId;
    }

    public static function canWrite(User $user, int $municipalityId): bool
    {
        if (in_array($user->role, UserRole::superRoles(), true)) {
            return self::canView($user, $municipalityId);
        }

        return $user->municipality_id !== null && (int) $user->municipality_id === $municipalityId;
    }

    public static function canModerate(User $user, int $municipalityId): bool
    {
        return self::isStaff($user) && self::canView($user, $municipalityId);
    }

    /** Escreve como "Equipa KeruGás": Super Admin/PSA num município que não é o seu. */
    public static function writesAsTeam(User $user, int $municipalityId): bool
    {
        return in_array($user->role, UserRole::superRoles(), true)
            && (int) $user->municipality_id !== $municipalityId;
    }

    /** Apagar de vez e gerir bairros/palavras: Admin para cima. */
    public static function canAdminister(User $user): bool
    {
        return in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN], true);
    }

    /** Duração máxima de um silenciamento em horas (null = pode ser permanente; 0 = não pode). */
    public static function maxMuteHours(User $user): ?int
    {
        return match ($user->role) {
            UserRole::MODERATOR => 24,
            UserRole::ADMIN => 24 * 7,
            UserRole::PROVINCE_SUPER_ADMIN, UserRole::SUPER_ADMIN => null,
            default => 0,
        };
    }

    /**
     * Municípios que a pessoa pode ver. `null` = todos (Super Admin, Moderador).
     *
     * @return list<int>|null
     */
    public static function viewableMunicipalityIds(User $user): ?array
    {
        if ($user->role === UserRole::SUPER_ADMIN || $user->role === UserRole::MODERATOR) {
            return null;
        }

        if (in_array($user->role, [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN], true)) {
            $province = $user->scopedProvinceId();

            return $province ? Municipality::query()->where('province_id', $province)->pluck('id')->all() : [];
        }

        return $user->municipality_id ? [(int) $user->municipality_id] : [];
    }

    /** O município por omissão: o da própria pessoa. */
    public static function defaultMunicipalityId(User $user): ?int
    {
        return $user->municipality_id ? (int) $user->municipality_id : null;
    }

    /** Etiqueta mostrada junto ao nome. */
    public static function badge(User $author, bool $official): ?string
    {
        if ($official) {
            return 'team';
        }

        return match ($author->role) {
            UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN => 'team',
            UserRole::MODERATOR => 'moderator',
            UserRole::DRIVER => 'driver',
            UserRole::STATION_OPERATOR => 'station',
            default => null,
        };
    }
}
