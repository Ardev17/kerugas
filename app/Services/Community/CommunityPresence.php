<?php

namespace App\Services\Community;

use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Support\Facades\Cache;

/**
 * "Online agora" e "visto há X" dos Vizinhos KeruGás (Outubro 2026).
 * `users.last_seen_at` é atualizado no máximo uma vez por minuto (ver o
 * middleware TouchLastSeen); só se mostra a quem está no mesmo município.
 */
class CommunityPresence
{
    public const ONLINE_MINUTES = 5;

    public const LIST_LIMIT = 40;

    public static function touch(User $user): void
    {
        $key = 'presence:' . $user->id;
        if (Cache::add($key, 1, 60)) {
            User::query()->whereKey($user->id)->update(['last_seen_at' => now()]);
        }
    }

    /**
     * Só os dois números (Velocidade, Outubro 2026): a entrada no Vizinhos
     * não precisa da lista de pessoas — uma consulta em vez de quatro.
     *
     * @return array{online: int, active_today: int}
     */
    public function counts(int $municipalityId): array
    {
        $row = User::query()->toBase()
            ->where('municipality_id', $municipalityId)->where('status', 'ACTIVE')
            ->whereNull('deletion_scheduled_for')
            ->where('last_seen_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as today, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as online', [now()->subMinutes(self::ONLINE_MINUTES)])
            ->first();

        return ['online' => (int) ($row->online ?? 0), 'active_today' => (int) ($row->today ?? 0)];
    }

    /** @return array{online: int, active_today: int, people: list<array<string, mixed>>} */
    public function forMunicipality(int $municipalityId, User $viewer): array
    {
        $base = User::query()->where('municipality_id', $municipalityId)->where('status', 'ACTIVE')
            ->whereNull('deletion_scheduled_for')->whereNotNull('last_seen_at');

        ['online' => $online, 'active_today' => $today] = $this->counts($municipalityId);

        $people = (clone $base)->where('id', '!=', $viewer->id)->where('last_seen_at', '>=', now()->subDays(7))
            ->with('neighborhood:id,name')->orderByDesc('last_seen_at')->limit(self::LIST_LIMIT)
            ->get(['id', 'name', 'role', 'avatar_path', 'neighborhood_id', 'last_seen_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar_url' => AvatarService::url($u->avatar_path),
                'badge' => CommunityAccess::badge($u, false),
                'neighborhood' => $u->neighborhood?->name,
                'online' => $u->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES)),
                'last_seen_at' => $u->last_seen_at->toIso8601String(),
            ])->values()->all();

        return ['online' => $online, 'active_today' => $today, 'people' => $people];
    }
}
