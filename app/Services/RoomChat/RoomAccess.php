<?php

namespace App\Services\RoomChat;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\RoomMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem vê e escreve em cada sala (Outubro 2026).
 *
 * Conversa dos condutores (room_id = município) — mesmas regras do chat por
 * município: o Condutor só o seu município; Admin e Super Admin Provincial os
 * municípios da sua província; o Super Admin todos.
 *
 * Conversa do investidor (room_id = investidor) — privada entre o investidor
 * e os Super Admins gerais; nenhum outro investidor a vê.
 *
 * Conversa com a Central (room_id = utilizador) — canal direto entre um
 * utilizador e a direção: o próprio utilizador; Admin e Provincial da
 * província do utilizador; o Super Admin todos. Só os admins começam a
 * conversa — o utilizador só responde depois de receber uma mensagem.
 */
class RoomAccess
{
    /** @return list<UserRole> */
    public static function staffRoles(): array
    {
        return [UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN];
    }

    public static function isStaff(User $user): bool
    {
        return in_array($user->role, self::staffRoles(), true);
    }

    public static function canView(User $user, string $type, int $roomId): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }

        return match ($type) {
            RoomMessage::DRIVERS => self::canViewDrivers($user, $roomId),
            RoomMessage::CENTRAL => self::canViewCentral($user, $roomId),
            RoomMessage::INVESTOR => self::canViewInvestor($user, $roomId),
            default => false,
        };
    }

    public static function canWrite(User $user, string $type, int $roomId): bool
    {
        if (!self::canView($user, $type, $roomId)) {
            return false;
        }

        // Central: o utilizador só responde depois de a direção lhe escrever.
        if ($type === RoomMessage::CENTRAL && !self::isStaff($user)) {
            return self::centralStarted($roomId);
        }

        return true;
    }

    /** Admins com acesso à sala podem remover mensagens de outros. */
    public static function moderates(User $user, string $type, int $roomId): bool
    {
        return self::isStaff($user) && self::canView($user, $type, $roomId);
    }

    public static function centralStarted(int $userId): bool
    {
        return RoomMessage::query()->room(RoomMessage::CENTRAL, $userId)
            ->whereHas('user', fn ($q) => $q->whereIn('role', array_map(fn ($r) => $r->value, self::staffRoles())))
            ->exists();
    }

    /** Pessoas que se podem mencionar nesta sala. */
    public static function participants(string $type, int $roomId): Builder
    {
        $q = User::query()->where('status', 'ACTIVE')->whereNull('deletion_scheduled_for');
        $staff = array_map(fn ($r) => $r->value, self::staffRoles());

        if ($type === RoomMessage::DRIVERS) {
            $provinceId = Municipality::query()->whereKey($roomId)->value('province_id');

            return $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('role', UserRole::DRIVER)->where('municipality_id', $roomId))
                ->orWhere(fn ($a) => $a->whereIn('role', [UserRole::ADMIN->value, UserRole::PROVINCE_SUPER_ADMIN->value])->where('province_id', $provinceId))
                ->orWhere('role', UserRole::SUPER_ADMIN));
        }

        // Investidor: ele e os Super Admins gerais (mais ninguém).
        if ($type === RoomMessage::INVESTOR) {
            return $q->where(fn ($w) => $w->where(fn ($i) => $i->whereKey($roomId)->where('role', UserRole::INVESTOR))->orWhere('role', UserRole::SUPER_ADMIN));
        }

        // Central: o utilizador e os admins que já escreveram na conversa.
        $writers = RoomMessage::query()->room(RoomMessage::CENTRAL, $roomId)->distinct()->pluck('user_id');

        return $q->where(fn ($w) => $w->whereKey($roomId)->orWhere(fn ($a) => $a->whereIn('id', $writers)->whereIn('role', $staff)));
    }

    /** Tipo mostrado junto ao nome. */
    public static function typeLabel(UserRole $role): ?string
    {
        return match ($role) {
            UserRole::SUPER_ADMIN => 'Admin Geral',
            UserRole::PROVINCE_SUPER_ADMIN => 'Admin Provincial',
            UserRole::ADMIN => 'Admin',
            UserRole::DRIVER => 'Condutor',
            UserRole::STATION_OPERATOR => 'Operador',
            UserRole::INVESTOR => 'Investidor',
            default => null,
        };
    }

    public static function channel(string $type, int $roomId): string
    {
        return match ($type) {
            RoomMessage::DRIVERS => "municipality.{$roomId}.chat",
            RoomMessage::INVESTOR => "investor.{$roomId}",
            default => "central.{$roomId}",
        };
    }

    private static function canViewDrivers(User $user, int $municipalityId): bool
    {
        if ($user->role === UserRole::SUPER_ADMIN) {
            return Municipality::query()->whereKey($municipalityId)->exists();
        }
        if ($user->role === UserRole::DRIVER) {
            return (int) $user->municipality_id === $municipalityId;
        }
        if (in_array($user->role, [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN], true)) {
            $provinceId = Municipality::query()->whereKey($municipalityId)->value('province_id');

            return $provinceId !== null && $user->canAccessProvince((int) $provinceId);
        }

        return false;
    }

    private static function canViewCentral(User $user, int $targetId): bool
    {
        if ($user->id === $targetId) {
            return !self::isStaff($user);
        }
        if (!self::isStaff($user)) {
            return false;
        }

        $target = User::query()->find($targetId);
        if (!$target || self::isStaff($target)) {
            return false; // a Central conversa com utilizadores, não com outros admins
        }

        return $user->role === UserRole::SUPER_ADMIN || $user->canAccessProvince($target->province_id);
    }

    /**
     * Conversa privada do investidor (Outubro 2026): SÓ o próprio investidor e
     * os Super Admins gerais. Nenhum outro investidor, nem Admin/Provincial.
     */
    private static function canViewInvestor(User $user, int $investorId): bool
    {
        if ($user->id === $investorId) {
            return $user->role === UserRole::INVESTOR;
        }
        if ($user->role !== UserRole::SUPER_ADMIN) {
            return false;
        }

        return User::query()->whereKey($investorId)->where('role', UserRole::INVESTOR)->exists();
    }
}
