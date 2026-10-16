<?php

namespace App\Services\RoomChat;

use App\Models\RoomMessage;
use App\Models\RoomMessageMention;
use App\Models\RoomMessageReaction;
use App\Models\User;
use App\Services\Media\AvatarService;
use App\Support\ReactionSummary;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mensagens das conversas por sala em JSON, no MESMO formato dos Vizinhos
 * (o ecrã reaproveita o cartão de mensagem). O autor leva nome, tipo
 * (Condutor, Admin, Admin Provincial, Admin Geral…) e província/município.
 */
class RoomChatPresenter
{
    public function __construct(private readonly RoomChatService $service)
    {
    }

    /**
     * @param  Collection<int, RoomMessage>  $messages
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

        $reactions = RoomMessageReaction::query()->with('user:id,name,avatar_path')->whereIn('message_id', $ids)->get()->groupBy('message_id');
        $mentionRows = RoomMessageMention::query()->whereIn('message_id', $ids)->get()->groupBy('message_id');

        $tokenIds = [];
        foreach ($messages as $m) {
            if (preg_match_all('/@\[(\d+)\]/', (string) $m->body, $found)) {
                array_push($tokenIds, ...array_map('intval', $found[1]));
            }
        }
        $names = $tokenIds ? User::query()->whereIn('id', array_unique($tokenIds))->pluck('name', 'id') : collect();

        $moderates = [];

        return $messages->map(function (RoomMessage $m) use ($viewer, $reactions, $mentionRows, $names, &$moderates) {
            $key = $m->room_type . ':' . $m->room_id;
            $moderates[$key] ??= RoomAccess::moderates($viewer, $m->room_type, $m->room_id);

            return $this->one($m, $viewer, $reactions, $mentionRows, $names, $moderates[$key]);
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public static function authorOf(User $u, string $roomType): array
    {
        $staff = RoomAccess::isStaff($u);

        return [
            'id' => $u->id,
            'name' => $u->name,
            'avatar_url' => AvatarService::url($u->avatar_path),
            'badge' => $staff ? 'team' : null,
            'neighborhood' => null,
            'role' => $u->role->value,
            // Na Central, quem escreve pela direção aparece como tal.
            'type_label' => match (true) {
                $roomType === RoomMessage::CENTRAL && $staff => 'Central KeruGás',
                $roomType === RoomMessage::INVESTOR && $u->role === \App\Enums\UserRole::SUPER_ADMIN => 'Super Admin',
                default => RoomAccess::typeLabel($u->role),
            },
            'province' => $staff ? $u->province?->name : null,
            'municipality' => $u->municipality?->name,
        ];
    }

    private function one(RoomMessage $m, User $viewer, Collection $reactions, Collection $mentionRows, Collection $names, bool $moderator): array
    {
        $mine = $m->user_id === $viewer->id;
        $status = $m->author_deleted_at ? 'deleted' : ($m->removed_at ? 'removed' : 'visible');
        $showContent = $status === 'visible' || ($mine && $status !== 'deleted') || ($moderator && $status === 'removed');

        $body = $showContent ? (string) $m->body : null;
        $mentioned = [];
        if ($body !== null && preg_match_all('/@\[(\d+)\]/', $body, $found)) {
            foreach (array_unique($found[1]) as $id) {
                $mentioned[(int) $id] = (string) ($names[(int) $id] ?? 'colega');
            }
        }

        $summary = ReactionSummary::of($reactions->get($m->id, collect()), (int) $viewer->id);

        $reply = null;
        if ($m->replyTo) {
            $visible = $m->replyTo->isVisible();
            $reply = [
                'id' => $m->replyTo->id,
                'author_name' => $visible ? $m->replyTo->user?->name : null,
                'excerpt' => $visible ? Str::limit($this->service->plainText((string) $m->replyTo->body) ?: ($m->replyTo->image_path ? 'Foto' : 'Localização'), 90) : null,
                'available' => $visible,
            ];
        }

        return [
            'id' => $m->id,
            'municipality_id' => $m->room_type === RoomMessage::DRIVERS ? $m->room_id : 0,
            'status' => $status,
            'topic' => null,
            'neighborhood' => null,
            'official' => false,
            'pinned' => false,
            'edited' => $m->edited_at !== null,
            'created_at' => $m->created_at?->toIso8601String(),
            'author' => $showContent && $m->user ? self::authorOf($m->user, $m->room_type) : null,
            'body' => $body,
            'mentions' => (object) $mentioned,
            'image_url' => $status === 'visible' && $m->image_path ? url('/api/v1/rooms/images/' . basename((string) $m->image_path)) : null,
            'has_image' => $m->image_path !== null,
            'reply_to' => $reply,
            'reactions' => (object) $summary['counts'],
            'my_reaction' => $summary['mine'],
            'reactors' => $summary['reactors'],
            'mine' => $mine,
            'mentions_me' => $mentionRows->get($m->id, collect())->contains('user_id', $viewer->id),
            'location' => $this->location($m, $mine, $status),
            'can_edit' => $mine && $status === 'visible' && !$m->hasLocation() && $m->created_at->gte(now()->subMinutes(RoomChatService::EDIT_MINUTES)),
            'can_delete' => ($mine && $status !== 'deleted') || ($moderator && $status === 'visible'),
            'can_report' => false,
            'can_moderate' => $moderator,
            'removal_reason' => ($moderator || $mine) ? $m->removal_reason : null,
        ];
    }

    /** Mesmo formato da localização dos Vizinhos; coordenadas só enquanto ativa. */
    private function location(RoomMessage $m, bool $mine, string $status): ?array
    {
        if (!$m->hasLocation()) {
            return null;
        }
        $active = $m->locationActive() && $status === 'visible';

        return [
            'kind' => 'point',
            'label' => null,
            'active' => $active,
            'expires_at' => $m->location_expires_at?->toIso8601String(),
            'ended_at' => $m->location_ended_at?->toIso8601String(),
            'can_stop' => $mine && $active,
            'latitude' => $active ? (float) $m->location_lat : null,
            'longitude' => $active ? (float) $m->location_lng : null,
            'accuracy' => $active ? $m->location_accuracy : null,
            'station' => null,
        ];
    }
}
