<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\RoomMessage;
use App\Services\Media\AvatarService;
use App\Services\RoomChat\RoomChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat por município (pedido explícito do cliente, Setembro 2026).
 * Não vive sob /admin porque um dos três papéis com acesso — DRIVER —
 * não é uma conta administrativa; a rota fica em /chat, protegida só
 * por `auth:sanctum` + a permissão granular `chat.view`/`chat.send`
 * (concedida a ADMIN, DRIVER, e SUPER_ADMIN via o `'*'` do
 * RolePermissions).
 *
 * ÂMBITO DE VISIBILIDADE (pedido explícito do cliente):
 *   - Condutor: só o seu próprio município — nem sequer pode escolher
 *     outro (parâmetros `province_id`/`municipality_id` do pedido são
 *     ignorados para este papel).
 *   - Admin: a sua própria província, com filtro OPCIONAL por
 *     município (`municipality_id`) dentro dela.
 *   - Super Admin: tudo, com filtro OPCIONAL por província
 *     (`province_id`) e depois por município (`municipality_id`).
 */
class ChatController extends Controller
{
    /*
     * Outubro 2026: as mensagens passaram para a "conversa dos condutores"
     * (room_messages, /rooms/drivers/{município}/…, com menções, fotos,
     * reações e localização). Estas duas rotas ficam para as versões
     * antigas da app — leem e escrevem na mesma conversa, só com texto.
     */
    public function __construct(private readonly RoomChatService $rooms)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = RoomMessage::query()->with('user')->where('room_type', RoomMessage::DRIVERS)->visible();

        match ($user->role) {
            UserRole::DRIVER => $query->where('room_id', $user->municipality_id),
            UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN => $this->scopeAdmin($request, $query),
            UserRole::SUPER_ADMIN => $this->scopeSuperAdmin($request, $query),
            default => abort(403, 'O seu papel não tem acesso ao chat.'),
        };

        $messages = $query->latest('id')->paginate(50);
        $provinces = Municipality::query()->whereIn('id', $messages->getCollection()->pluck('room_id')->unique())->pluck('province_id', 'id');

        return response()->json([
            'success' => true,
            'data' => $messages->getCollection()->map(fn (RoomMessage $m) => $this->legacy($m, $provinces))->values(),
            'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage(), 'total' => $messages->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'body' => ['required', 'string', 'max:500'],
        ]);

        $message = $this->rooms->post($request->user(), RoomMessage::DRIVERS, (int) $data['municipality_id'], ['body' => $data['body']]);
        $provinces = Municipality::query()->whereKey($message->room_id)->pluck('province_id', 'id');

        return response()->json(['success' => true, 'data' => $this->legacy($message->load('user'), $provinces)], 201);
    }

    /** Formato antigo (ChatMessageResource). */
    private function legacy(RoomMessage $m, $provinces): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body !== '' ? $m->body : ($m->image_path ? '[Foto]' : ($m->hasLocation() ? '[Localização partilhada]' : '')),
            'user' => $m->user?->name,
            'user_id' => $m->user_id,
            'user_avatar_url' => AvatarService::url($m->user?->avatar_path),
            'role' => $m->user?->role?->value,
            'province_id' => $provinces[$m->room_id] ?? null,
            'municipality_id' => $m->room_id,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function scopeAdmin(Request $request, $query): void
    {
        $query->whereIn('room_id', Municipality::query()->where('province_id', $request->user()->province_id ?? 0)->select('id'));

        if ($municipalityId = $request->query('municipality_id')) {
            $query->where('room_id', $municipalityId);
        }
    }

    private function scopeSuperAdmin(Request $request, $query): void
    {
        if ($provinceId = $request->query('province_id')) {
            $query->whereIn('room_id', Municipality::query()->where('province_id', $provinceId)->select('id'));
        }

        if ($municipalityId = $request->query('municipality_id')) {
            $query->where('room_id', $municipalityId);
        }
    }
}
