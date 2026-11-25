<?php

namespace App\Services\StaffChat;

use App\Models\StaffChatMention;
use App\Models\StaffChatMessage;
use App\Models\StaffChatReaction;
use App\Models\User;
use App\Services\Media\AvatarService;
use App\Support\ReactionSummary;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mensagens do Chat da Equipa em JSON, no MESMO formato dos Vizinhos
 * (para o ecrã reaproveitar o cartão de mensagem), mais o autor identificado
 * por nome, tipo (Admin Geral / Admin Provincial / Admin), província e município.
 *
 *  - toda a gente (admins): mensagens visíveis; removidas aparecem como aviso
 *    sem texto nem autor;
 *  - o autor vê o seu texto e o motivo da remoção;
 *  - o Super Admin geral vê tudo (removidas, apagadas pelo autor, motivos).
 */
class StaffChatPresenter
{
    public function __construct(private readonly StaffChatService $service)
    {
    }

    /**
     * @param  Collection<int, StaffChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function present(Collection $messages, User $viewer): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        $messages = EloquentCollection::make($messages->all());
        $messages->loadMissing(['user:id,name,role,avatar_path,province_id,municipality_id', 'user.province:id,name', 'user.municipality:id,name', 'replyTo.user:id,name']);
        $ids = $messages->pluck('id');

        $reactions = StaffChatReaction::query()->with('user:id,name,avatar_path')->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $mentionRows = StaffChatMention::query()->whereIn('message_id', $ids)->get()->groupBy('message_id');

        $tokenIds = [];
        foreach ($messages as $m) {
            if (preg_match_all('/@\[(\d+)\]/', (string) $m->body, $found)) {
                array_push($tokenIds, ...array_map('intval', $found[1]));
            }
        }
        $names = $tokenIds ? User::query()->whereIn('id', array_unique($tokenIds))->pluck('name', 'id') : collect();

        return $messages->map(fn (StaffChatMessage $m) => $this->one($m, $viewer, $reactions, $mentionRows, $names))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function authorOf(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'avatar_url' => AvatarService::url($u->avatar_path),
            'badge' => 'team',
            'neighborhood' => null,
            'role' => $u->role->value,
            'type_label' => StaffChatAccess::typeLabel($u->role),
            'province' => $u->province?->name,
            'municipality' => $u->municipality?->name,
        ];
    }

    private function one(StaffChatMessage $m, User $viewer, Collection $reactions, Collection $mentionRows, Collection $names): array
    {
        $moderator = StaffChatAccess::moderates($viewer);
        $mine = $m->user_id === $viewer->id;
        $status = $m->author_deleted_at ? 'deleted' : ($m->removed_at ? 'removed' : 'visible');
        $showContent = $status === 'visible' || $moderator || ($mine && $status !== 'deleted');

        $body = $showContent ? (string) $m->body : null;
        $mentioned = [];
        if ($body !== null && preg_match_all('/@\[(\d+)\]/', $body, $found)) {
            foreach (array_unique($found[1]) as $id) {
                $mentioned[(int) $id] = (string) ($names[(int) $id] ?? 'colega');
            }
        }

        // Quem reagiu fica visível a todos os participantes (Outubro 2026).
        $summary = ReactionSummary::of($reactions->get($m->id, collect()), (int) $viewer->id);
        $counts = $summary['counts'];
        $mineReaction = $summary['mine'];

        $reply = null;
        if ($m->replyTo) {
            $visible = $m->replyTo->isVisible();
            $reply = [
                'id' => $m->replyTo->id,
                'author_name' => $visible ? $m->replyTo->user?->name : null,
                'excerpt' => $visible ? Str::limit($this->service->plainText((string) $m->replyTo->body) ?: 'Foto', 90) : null,
                'available' => $visible,
            ];
        }

        return [
            'id' => $m->id,
            'municipality_id' => 0,
            'status' => $status,
            'topic' => null,
            'neighborhood' => null,
            'official' => false,
            'pinned' => $m->pinned_at !== null,
            'edited' => $m->edited_at !== null,
            'created_at' => $m->created_at?->toIso8601String(),
            'author' => $showContent && $m->user ? self::authorOf($m->user) : null,
            'body' => $body,
            'mentions' => (object) $mentioned,
            'image_url' => $status === 'visible' && $m->image_path ? url('/api/v1/staff-chat/images/' . basename((string) $m->image_path)) : null,
            'has_image' => $m->image_path !== null,
            'reply_to' => $reply,
            'reactions' => (object) $counts,
            'my_reaction' => $mineReaction,
            'reactors' => $summary['reactors'],
            'mine' => $mine,
            'mentions_me' => $mentionRows->get($m->id, collect())->contains('user_id', $viewer->id),
            'location' => null,
            'can_edit' => $mine && $status === 'visible' && $m->created_at->gte(now()->subMinutes(StaffChatService::EDIT_MINUTES)),
            'can_delete' => $mine && $status !== 'deleted',
            'can_report' => false,
            'can_moderate' => $moderator,
            'removal_reason' => ($moderator || $mine) ? $m->removal_reason : null,
            'can_hard_delete' => $moderator,
        ];
    }
}
