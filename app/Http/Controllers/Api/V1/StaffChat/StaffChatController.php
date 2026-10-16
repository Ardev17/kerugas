<?php

namespace App\Http\Controllers\Api\V1\StaffChat;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommunityReaction;
use App\Models\Province;
use App\Models\StaffChatMessage;
use App\Models\StaffChatMute;
use App\Models\User;
use App\Services\Media\AvatarService;
use App\Services\StaffChat\StaffChatAccess;
use App\Services\StaffChat\StaffChatPresenter;
use App\Services\StaffChat\StaffChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chat da Equipa (Outubro 2026) — a sala única dos admins de todo o país.
 * A rota só deixa entrar Super Admin, Super Admin Provincial e Admin.
 */
class StaffChatController extends Controller
{
    public const WINDOW = 30;

    public const ONLINE_MINUTES = 5;

    public function __construct(
        private readonly StaffChatService $service,
        private readonly StaffChatPresenter $presenter,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $mute = $this->service->activeMute($user);

        $staff = User::query()->whereIn('role', StaffChatAccess::roleValues())->where('status', 'ACTIVE')->whereNull('deletion_scheduled_for');
        $row = (clone $staff)->toBase()->where('last_seen_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as today, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as online', [now()->subMinutes(self::ONLINE_MINUTES)])->first();

        return response()->json(['success' => true, 'data' => [
            'me' => [
                'type' => $user->role->value,
                'type_label' => StaffChatAccess::typeLabel($user->role),
                'can_write' => $mute === null,
                'muted' => $mute !== null,
                'muted_until' => $mute?->expires_at?->toIso8601String(),
                'is_super' => StaffChatAccess::moderates($user),
            ],
            'emojis' => CommunityReaction::EMOJIS,
            'types' => collect(StaffChatAccess::roles())->map(fn (UserRole $r) => ['key' => $r->value, 'label' => StaffChatAccess::typeLabel($r)])->values(),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name']),
            'limits' => ['max_body' => StaffChatService::MAX_BODY, 'edit_minutes' => StaffChatService::EDIT_MINUTES, 'max_mentions' => StaffChatService::MAX_MENTIONS],
            'members' => (clone $staff)->count(),
            'presence' => ['online' => (int) ($row->online ?? 0), 'active_today' => (int) ($row->today ?? 0)],
        ]]);
    }

    /**
     * Janela de 30 mensagens, da mais antiga para a mais recente. Sem cursor:
     * as mais recentes. `before_id` / `after_id` / `around_id` como nos Vizinhos.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'before_id' => ['nullable', 'integer'], 'after_id' => ['nullable', 'integer'], 'around_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', Rule::in(StaffChatAccess::roleValues())],
            'province_id' => ['nullable', 'integer'],
        ]);

        $base = fn () => $this->scopedQuery($request);
        $limit = self::WINDOW;

        if ($around = $request->integer('around_id')) {
            if (!$base()->whereKey($around)->exists()) {
                abort(response()->json(['success' => false, 'message' => 'Essa mensagem já não está disponível.'], 404));
            }
            $before = $base()->where('id', '<', $around)->orderByDesc('id')->limit(intdiv($limit, 2))->get();
            $after = $base()->where('id', '>', $around)->orderBy('id')->limit(intdiv($limit, 2))->get();
            $rows = $before->reverse()->push($base()->find($around))->concat($after)->values();
        } elseif ($beforeId = $request->integer('before_id')) {
            $rows = $base()->where('id', '<', $beforeId)->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        } elseif ($afterId = $request->integer('after_id')) {
            $rows = $base()->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->values();
        } else {
            $rows = $base()->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        }

        $oldest = $rows->first()?->id;
        $newest = $rows->last()?->id;

        $payload = [
            'success' => true,
            'data' => $this->presenter->present($rows, $user),
            'meta' => [
                'has_older' => $oldest !== null && $base()->where('id', '<', $oldest)->exists(),
                'has_newer' => $newest !== null && $base()->where('id', '>', $newest)->exists(),
                'oldest_id' => $oldest, 'newest_id' => $newest, 'municipality_id' => 0,
            ],
        ];

        if (!$request->filled('before_id') && !$request->filled('after_id')) {
            $pinned = StaffChatMessage::query()->visible()->whereNotNull('pinned_at')->orderByDesc('pinned_at')->limit(StaffChatService::MAX_PINNED)->get();
            $payload['pinned'] = $this->presenter->present($pinned, $user);
        }

        return response()->json($payload);
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $q = StaffChatMessage::query();

        if (!StaffChatAccess::moderates($user)) {
            $q->whereNull('author_deleted_at');
        }
        if ($type = $request->query('type')) {
            $q->whereHas('user', fn ($u) => $u->where('role', $type));
        }
        if ($province = $request->integer('province_id')) {
            $q->whereHas('user', fn ($u) => $u->where('province_id', $province));
        }
        if ($request->boolean('mine')) {
            $q->where('user_id', $user->id);
        }
        if ($request->boolean('mentions_me')) {
            $q->whereHas('mentions', fn ($m) => $m->where('user_id', $user->id));
        }
        if (($text = trim((string) $request->query('q'))) !== '') {
            $q->where('body', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%');
        }

        return $q;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:2000'],
            'reply_to_id' => ['nullable', 'integer'],
            'image' => ['nullable', 'file', 'max:5120'],
        ]);

        $message = $this->service->post($request->user(), $data, $request->file('image'));

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]], 201);
    }

    public function update(Request $request, StaffChatMessage $message): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $message = $this->service->edit($request->user(), $message, $data['body']);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    public function destroy(Request $request, StaffChatMessage $message): JsonResponse
    {
        $this->service->authorDelete($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function react(Request $request, StaffChatMessage $message): JsonResponse
    {
        $data = $request->validate(['emoji' => ['nullable', 'string', Rule::in(CommunityReaction::EMOJIS)]]);
        $this->service->react($request->user(), $message, $data['emoji'] ?? null);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    /** Sugestões de @: outros admins (nome, tipo, província, município; nunca o número). */
    public function mentionable(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        $people = $this->service->mentionable()->where('id', '!=', $request->user()->id)
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%' . $escaped . '%'))
            ->with('province:id,name', 'municipality:id,name')->orderBy('name')->limit(8)
            ->get(['id', 'name', 'role', 'avatar_path', 'province_id', 'municipality_id'])
            ->map(fn (User $u) => StaffChatPresenter::authorOf($u));

        return response()->json(['success' => true, 'data' => $people]);
    }

    public function presence(Request $request): JsonResponse
    {
        $me = $request->user();
        $people = $this->service->mentionable()->whereNotNull('last_seen_at')->where('id', '!=', $me->id)
            ->where('last_seen_at', '>=', now()->subDays(7))
            ->with('province:id,name', 'municipality:id,name')->orderByDesc('last_seen_at')->limit(60)
            ->get(['id', 'name', 'role', 'avatar_path', 'province_id', 'municipality_id', 'last_seen_at'])
            ->map(fn (User $u) => StaffChatPresenter::authorOf($u) + [
                'online' => $u->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES)),
                'last_seen_at' => $u->last_seen_at->toIso8601String(),
            ])->values();

        $counts = $this->service->mentionable()->toBase()->where('last_seen_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as today, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as online', [now()->subMinutes(self::ONLINE_MINUTES)])->first();

        return response()->json(['success' => true, 'data' => [
            'online' => (int) ($counts->online ?? 0), 'active_today' => (int) ($counts->today ?? 0), 'people' => $people,
        ]]);
    }

    // ── Moderação (Super Admin geral) ───────────────────────────────────

    public function pin(Request $request, StaffChatMessage $message): JsonResponse
    {
        $this->service->pin($request->user(), $message, true);

        return response()->json(['success' => true]);
    }

    public function unpin(Request $request, StaffChatMessage $message): JsonResponse
    {
        $this->service->pin($request->user(), $message, false);

        return response()->json(['success' => true]);
    }

    public function remove(Request $request, StaffChatMessage $message): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->service->remove($request->user(), $message, $data['reason']);

        return response()->json(['success' => true]);
    }

    public function restore(Request $request, StaffChatMessage $message): JsonResponse
    {
        $this->service->restore($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function purge(Request $request, StaffChatMessage $message): JsonResponse
    {
        $this->service->hardDelete($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function mutes(Request $request): JsonResponse
    {
        abort_unless(StaffChatAccess::moderates($request->user()), 403);

        $rows = StaffChatMute::query()->active()->with('user:id,name')->latest('id')->limit(100)->get()->map(fn ($m) => [
            'user_id' => $m->user_id, 'name' => $m->user?->name, 'reason' => $m->reason,
            'expires_at' => $m->expires_at?->toIso8601String(), 'permanent' => $m->expires_at === null,
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function mute(Request $request, User $target): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $this->service->mute($request->user(), $target, $data['hours'] ?? null, $data['reason'] ?? null);

        return response()->json(['success' => true]);
    }

    public function unmute(Request $request, User $target): JsonResponse
    {
        $this->service->unmute($request->user(), $target);

        return response()->json(['success' => true]);
    }
}
