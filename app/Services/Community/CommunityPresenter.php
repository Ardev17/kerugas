<?php

namespace App\Services\Community;

use App\Models\CommunityMention;
use App\Models\CommunityMessage;
use App\Models\CommunityReaction;
use App\Models\CommunityReport;
use App\Models\User;
use App\Services\Media\AvatarService;
use App\Support\ReactionSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Converte mensagens em JSON para UMA pessoa concreta, com consultas em
 * lote (sem N+1). O que cada pessoa vê:
 *  - toda a gente: mensagens visíveis; as removidas/em revisão aparecem
 *    como aviso, sem texto nem autor (mantém o fio das respostas);
 *  - o próprio autor: o seu texto e o motivo, se o removeram;
 *  - a equipa (na sua área): tudo, incluindo apagadas pelo autor, denúncias e motivos.
 */
class CommunityPresenter
{
    public function __construct(private readonly CommunityService $service)
    {
    }

    /**
     * @param  Collection<int, CommunityMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function present(Collection $messages, User $viewer): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        // loadMissing só existe na Collection do Eloquent, não na Support\Collection (collect()).
        $messages = \Illuminate\Database\Eloquent\Collection::make($messages->all());
        $messages->loadMissing(['user:id,name,role,avatar_path,neighborhood_id', 'user.neighborhood:id,name', 'neighborhood:id,name', 'replyTo.user:id,name', 'locationStation']);
        $ids = $messages->pluck('id');

        $reactions = CommunityReaction::query()->with('user:id,name,avatar_path')->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $mentionRows = CommunityMention::query()->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $reportCounts = CommunityAccess::isStaff($viewer)
            ? CommunityReport::query()->whereIn('message_id', $ids)->whereNull('resolved_at')->selectRaw('message_id, COUNT(*) c')->groupBy('message_id')->pluck('c', 'message_id')
            : collect();

        // Nomes das pessoas mencionadas (os @[id] do texto).
        $tokenIds = [];
        foreach ($messages as $m) {
            if (preg_match_all('/@\[(\d+)\]/', (string) $m->body, $found)) {
                array_push($tokenIds, ...array_map('intval', $found[1]));
            }
        }
        $names = $tokenIds ? User::query()->whereIn('id', array_unique($tokenIds))->pluck('name', 'id') : collect();

        return $messages->map(fn (CommunityMessage $m) => $this->one($m, $viewer, $reactions, $mentionRows, $reportCounts, $names))->values()->all();
    }

    private function one(CommunityMessage $m, User $viewer, Collection $reactions, Collection $mentionRows, Collection $reportCounts, Collection $names): array
    {
        $staff = CommunityAccess::canModerate($viewer, (int) $m->municipality_id);
        $mine = $m->user_id === $viewer->id;
        $status = $m->author_deleted_at ? 'deleted' : ($m->removed_at ? 'removed' : ($m->hidden_at ? 'hidden' : 'visible'));
        $showContent = $status === 'visible' || $staff || ($mine && $status !== 'deleted');

        $body = $showContent ? (string) $m->body : null;
        $mentioned = [];
        if ($body !== null && preg_match_all('/@\[(\d+)\]/', $body, $found)) {
            foreach (array_unique($found[1]) as $id) {
                $mentioned[(int) $id] = (string) ($names[(int) $id] ?? 'vizinho');
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

        $data = [
            'id' => $m->id,
            'municipality_id' => $m->municipality_id,
            'status' => $status,
            'topic' => $showContent ? $m->topic : null,
            'neighborhood' => $showContent && $m->neighborhood ? ['id' => $m->neighborhood->id, 'name' => $m->neighborhood->name] : null,
            'official' => (bool) $m->official,
            'pinned' => $m->pinned_at !== null,
            'edited' => $m->edited_at !== null,
            'created_at' => $m->created_at?->toIso8601String(),
            'author' => $showContent && $m->user ? [
                'id' => $m->user->id,
                'name' => $m->official ? 'Equipa KeruGás' : $m->user->name,
                'avatar_url' => $m->official ? null : AvatarService::url($m->user->avatar_path),
                'badge' => CommunityAccess::badge($m->user, (bool) $m->official),
                'neighborhood' => $m->user->neighborhood?->name,
            ] : null,
            'body' => $body,
            'mentions' => (object) $mentioned,
            'image_url' => $status === 'visible' ? CommunityImageService::url($m->image_path) : null,
            'has_image' => $m->image_path !== null,
            'reply_to' => $reply,
            'reactions' => (object) $counts,
            'my_reaction' => $mineReaction,
            'reactors' => $summary['reactors'],
            'mine' => $mine,
            'mentions_me' => $mentionRows->get($m->id, collect())->contains('user_id', $viewer->id),
            // Partilha de localização (Outubro 2026): coordenadas só enquanto ativa e visível.
            'location' => CommunityLocationService::present($m, $viewer, $status),
            'can_edit' => $mine && $status === 'visible' && $m->location_kind === null && $m->created_at->gte(now()->subMinutes(CommunityService::EDIT_MINUTES)),
            'can_delete' => $mine && $status !== 'deleted',
            'can_report' => !$mine && $status === 'visible',
            'can_moderate' => $staff,
            'removal_reason' => ($staff || $mine) ? $m->removal_reason : null,
        ];

        if ($staff) {
            $data['report_count'] = (int) ($reportCounts[$m->id] ?? 0);
            $data['can_hard_delete'] = CommunityAccess::canAdminister($viewer);
        }

        return $data;
    }
}
