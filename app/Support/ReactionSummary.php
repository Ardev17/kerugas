<?php

namespace App\Support;

use App\Services\Media\AvatarService;
use Illuminate\Support\Collection;

/**
 * Reações de uma mensagem (Outubro 2026): quantas por emoji, a minha e
 * QUEM reagiu — qualquer participante do chat pode ver a lista (Vizinhos,
 * Chat da Equipa e conversa dos condutores usam o mesmo formato).
 * As linhas devem vir com `user:id,name,avatar_path` carregado.
 */
final class ReactionSummary
{
    public const MAX_REACTORS = 200;

    /** @return array{counts: array<string,int>, mine: ?string, reactors: array<int, array<string,mixed>>} */
    public static function of(Collection $rows, int $viewerId): array
    {
        $counts = [];
        $mine = null;
        $reactors = [];

        foreach ($rows->sortBy('id') as $r) {
            $counts[$r->emoji] = ($counts[$r->emoji] ?? 0) + 1;
            if ((int) $r->user_id === $viewerId) {
                $mine = $r->emoji;
            }
            if (count($reactors) < self::MAX_REACTORS) {
                $reactors[] = [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->user?->name ?? 'Conta removida',
                    'avatar_url' => $r->user ? AvatarService::url($r->user->avatar_path) : null,
                    'emoji' => $r->emoji,
                    'me' => (int) $r->user_id === $viewerId,
                ];
            }
        }

        return ['counts' => $counts, 'mine' => $mine, 'reactors' => $reactors];
    }
}
