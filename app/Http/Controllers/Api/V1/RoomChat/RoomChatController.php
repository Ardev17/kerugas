<?php

namespace App\Http\Controllers\Api\V1\RoomChat;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommunityReaction;
use App\Models\Municipality;
use App\Models\RoomMessage;
use App\Models\User;
use App\Services\Community\CommunityImageService;
use App\Services\Media\AvatarService;
use App\Services\RoomChat\RoomAccess;
use App\Services\RoomChat\RoomChatPresenter;
use App\Services\RoomChat\RoomChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conversa dos condutores e Conversa com a Central (Outubro 2026).
 * /rooms/{tipo}/{sala}/… — tipo `drivers` (sala = município) ou `central`
 * (sala = utilizador). As regras de quem vê/escreve estão em RoomAccess.
 */
class RoomChatController extends Controller
{
    public const WINDOW = 30;

    public function __construct(
        private readonly RoomChatService $service,
        private readonly RoomChatPresenter $presenter,
    ) {
    }

    public function overview(Request $request, string $type, int $roomId): JsonResponse
    {
        $user = $request->user();
        $this->service->assertCanView($user, $type, $roomId);

        $title = match ($type) {
            RoomMessage::DRIVERS => 'Condutores · ' . (Municipality::query()->whereKey($roomId)->value('name') ?? 'Município'),
            RoomMessage::INVESTOR => 'Conversa com o Super Admin',
            default => 'Conversa com a Central',
        };
        $person = null;
        if ($type !== RoomMessage::DRIVERS && RoomAccess::isStaff($user)) {
            $target = User::query()->with(['province:id,name', 'municipality:id,name'])->find($roomId);
            $person = $target ? [
                'id' => $target->id, 'name' => $target->name, 'phone' => $target->phone,
                'avatar_url' => AvatarService::url($target->avatar_path),
                'type_label' => RoomAccess::typeLabel($target->role) ?? 'Utilizador',
                'province' => $target->province?->name, 'municipality' => $target->municipality?->name,
            ] : null;
            $title = 'Conversa com ' . ($target?->name ?? ($type === RoomMessage::INVESTOR ? 'investidor' : 'utilizador'));
        }

        return response()->json(['success' => true, 'data' => [
            'room' => ['type' => $type, 'id' => $roomId, 'title' => $title, 'person' => $person],
            'me' => [
                'can_write' => RoomAccess::canWrite($user, $type, $roomId),
                'is_staff' => RoomAccess::isStaff($user),
                'moderates' => RoomAccess::moderates($user, $type, $roomId),
            ],
            'emojis' => CommunityReaction::EMOJIS,
            'limits' => [
                'max_body' => RoomChatService::MAX_BODY,
                'edit_minutes' => RoomChatService::EDIT_MINUTES,
                'max_mentions' => RoomChatService::MAX_MENTIONS,
                'location_minutes' => RoomChatService::LOCATION_MINUTES,
            ],
        ]]);
    }

    /** Janela de 30 mensagens (mais antiga → mais recente), com before_id / after_id / around_id. */
    public function index(Request $request, string $type, int $roomId): JsonResponse
    {
        $user = $request->user();
        $this->service->assertCanView($user, $type, $roomId);
        $request->validate(['before_id' => ['nullable', 'integer'], 'after_id' => ['nullable', 'integer'], 'around_id' => ['nullable', 'integer']]);

        $base = fn () => RoomMessage::query()->room($type, $roomId)->whereNull('author_deleted_at');
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
        $hasNewer = $newest !== null && $base()->where('id', '>', $newest)->exists();

        // Viu as mais recentes → marca como lido.
        if ($newest !== null && !$hasNewer) {
            $this->markRead($user, $type, $roomId, (int) $newest);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presenter->present($rows, $user),
            'meta' => [
                'has_older' => $oldest !== null && $base()->where('id', '<', $oldest)->exists(),
                'has_newer' => $hasNewer,
                'oldest_id' => $oldest, 'newest_id' => $newest,
                'municipality_id' => $type === RoomMessage::DRIVERS ? $roomId : 0,
            ],
        ]);
    }

    public function store(Request $request, string $type, int $roomId): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:' . (RoomChatService::MAX_BODY + 200)],
            'reply_to_id' => ['nullable', 'integer'],
            'image' => ['nullable', 'file', 'max:' . CommunityImageService::MAX_KB],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'minutes' => ['nullable', 'integer'],
        ]);

        $message = $this->service->post($request->user(), $type, $roomId, $data, $request->file('image'));
        $this->markRead($request->user(), $type, $roomId, (int) $message->id);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]], 201);
    }

    public function update(Request $request, RoomMessage $message): JsonResponse
    {
        $this->service->assertCanView($request->user(), $message->room_type, $message->room_id);
        $data = $request->validate(['body' => ['required', 'string', 'max:' . (RoomChatService::MAX_BODY + 200)]]);
        $message = $this->service->edit($request->user(), $message, $data['body']);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    public function destroy(Request $request, RoomMessage $message): JsonResponse
    {
        $this->service->assertCanView($request->user(), $message->room_type, $message->room_id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->service->delete($request->user(), $message, $data['reason'] ?? null);

        return response()->json(['success' => true]);
    }

    public function react(Request $request, RoomMessage $message): JsonResponse
    {
        $this->service->assertCanView($request->user(), $message->room_type, $message->room_id);
        $data = $request->validate(['emoji' => ['nullable', 'string', Rule::in(CommunityReaction::EMOJIS)]]);
        $this->service->react($request->user(), $message, $data['emoji'] ?? null);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    public function stopLocation(Request $request, RoomMessage $message): JsonResponse
    {
        $this->service->assertCanView($request->user(), $message->room_type, $message->room_id);
        $this->service->stopLocation($request->user(), $message);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    public function mentionable(Request $request, string $type, int $roomId): JsonResponse
    {
        $this->service->assertCanView($request->user(), $type, $roomId);
        $q = trim((string) $request->query('q', ''));

        $people = RoomAccess::participants($type, $roomId)
            ->whereKeyNot($request->user()->id)
            ->when($q !== '', fn ($w) => $w->where('name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'))
            ->with(['province:id,name', 'municipality:id,name'])
            ->orderBy('name')->limit(8)->get();

        return response()->json(['success' => true, 'data' => $people->map(fn (User $u) => RoomChatPresenter::authorOf($u, $type))->values()]);
    }

    /** Fotos: nome gerado pelo servidor, só enquanto a mensagem estiver visível. */
    public function image(string $file): Response
    {
        abort_unless(preg_match('#^' . CommunityImageService::FILE_PATTERN . '$#', $file) === 1, 404);

        $path = CommunityImageService::DIR . '/' . $file;
        abort_unless(RoomMessage::query()->visible()->where('image_path', $path)->exists(), 404);

        $disk = Storage::disk(CommunityImageService::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    // ── Conversa com a Central ──────────────────────────────────────────

    /**
     * Para o utilizador: se a Central já lhe escreveu (o botão "Conversa com a
     * Central" só aparece nesse caso) e quantas mensagens novas tem.
     */
    public function centralMe(Request $request): JsonResponse
    {
        $user = $request->user();
        if (RoomAccess::isStaff($user)) {
            return response()->json(['success' => true, 'data' => ['available' => false, 'unread' => 0]]);
        }

        $started = RoomAccess::centralStarted($user->id);
        $read = (int) DB::table('room_reads')->where(['user_id' => $user->id, 'room_type' => RoomMessage::CENTRAL, 'room_id' => $user->id])->value('last_read_id');

        return response()->json(['success' => true, 'data' => [
            'available' => $started,
            'room_id' => $user->id,
            'unread' => $started ? RoomMessage::query()->room(RoomMessage::CENTRAL, $user->id)->visible()
                ->where('id', '>', $read)->where('user_id', '!=', $user->id)->count() : 0,
        ]]);
    }

    /** Para os admins: as conversas com a Central do seu âmbito (Admin/Provincial: a sua província). */
    public function centralThreads(Request $request): JsonResponse
    {
        $actor = $request->user();
        $scope = $actor->role === UserRole::SUPER_ADMIN ? null : $actor->scopedProvinceId();

        $latest = RoomMessage::query()->where('room_type', RoomMessage::CENTRAL)
            ->when($scope !== null, fn ($q) => $q->whereIn('room_id', User::query()->where('province_id', $scope)->select('id')))
            ->selectRaw('room_id, MAX(id) as last_id, COUNT(*) as total')->groupBy('room_id')
            ->orderByDesc('last_id')->limit(100)->get();

        $messages = RoomMessage::query()->with('user:id,name,role')->whereIn('id', $latest->pluck('last_id'))->get()->keyBy('id');
        $people = User::query()->with(['province:id,name', 'municipality:id,name'])->whereIn('id', $latest->pluck('room_id'))->get()->keyBy('id');
        $reads = DB::table('room_reads')->where('user_id', $actor->id)->where('room_type', RoomMessage::CENTRAL)
            ->whereIn('room_id', $latest->pluck('room_id'))->pluck('last_read_id', 'room_id');

        $data = $latest->map(function ($row) use ($messages, $people, $reads) {
            $p = $people->get($row->room_id);
            $m = $messages->get($row->last_id);
            if (!$p) {
                return null;
            }
            $read = (int) ($reads[$row->room_id] ?? 0);

            return [
                'user' => [
                    'id' => $p->id, 'name' => $p->name, 'phone' => $p->phone,
                    'avatar_url' => AvatarService::url($p->avatar_path),
                    'type_label' => RoomAccess::typeLabel($p->role) ?? 'Utilizador',
                    'province' => $p->province?->name, 'municipality' => $p->municipality?->name,
                ],
                'last_message' => $m ? [
                    'excerpt' => $m->isVisible() ? \Illuminate\Support\Str::limit($this->service->plainText((string) $m->body) ?: ($m->image_path ? 'Foto' : 'Localização'), 80) : 'Mensagem apagada',
                    'from_user' => $m->user_id === $p->id,
                    'author' => $m->user?->name,
                    'created_at' => $m->created_at?->toIso8601String(),
                ] : null,
                'total' => (int) $row->total,
                'unread' => RoomMessage::query()->room(RoomMessage::CENTRAL, $p->id)->visible()
                    ->where('id', '>', $read)->where('user_id', $p->id)->count(),
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function markRead(User $user, string $type, int $roomId, int $lastId): void
    {
        try {
            $row = DB::table('room_reads')->where(['user_id' => $user->id, 'room_type' => $type, 'room_id' => $roomId])->first();
            if (!$row) {
                DB::table('room_reads')->insert([
                    'user_id' => $user->id, 'room_type' => $type, 'room_id' => $roomId,
                    'last_read_id' => $lastId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ((int) $row->last_read_id < $lastId) {
                DB::table('room_reads')->where('id', $row->id)->update(['last_read_id' => $lastId, 'updated_at' => now()]);
            }
        } catch (\Throwable) {
            // corrida entre dois pedidos: o outro já marcou
        }
    }
}
