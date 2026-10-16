<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem tem de saber de uma ação numa província (Outubro 2026): os Super
 * Admins gerais + os Super Admins Provinciais DESSA província. Quem fez a
 * ação nunca é avisado de si próprio.
 */
final class SuperAdminAudience
{
    /** @return Collection<int, int> */
    public static function ids(?int $provinceId, ?int $exceptUserId = null): Collection
    {
        return User::query()
            ->where('status', 'ACTIVE')
            ->where(function ($q) use ($provinceId) {
                $q->where('role', UserRole::SUPER_ADMIN);
                if ($provinceId) {
                    $q->orWhere(fn ($p) => $p->where('role', UserRole::PROVINCE_SUPER_ADMIN)->where('province_id', $provinceId));
                }
            })
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->pluck('id');
    }
}
