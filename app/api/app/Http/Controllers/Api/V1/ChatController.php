<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Services\ChatService;
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
    public function __construct(private readonly ChatService $chatService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ChatMessage::query()->with('user');

        match ($user->role) {
            UserRole::DRIVER => $query->where('municipality_id', $user->municipality_id),
            UserRole::ADMIN => $this->scopeAdmin($request, $query),
            UserRole::SUPER_ADMIN => $this->scopeSuperAdmin($request, $query),
            default => abort(403, 'O seu papel não tem acesso ao chat.'),
        };

        $messages = $query->latest()->paginate(50);

        return response()->json([
            'success' => true,
            'data' => ChatMessageResource::collection($messages),
            'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage(), 'total' => $messages->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'body' => ['required', 'string', 'max:500'],
        ]);

        $message = $this->chatService->send($request->user(), (int) $data['municipality_id'], $data['body']);

        return response()->json(['success' => true, 'data' => new ChatMessageResource($message->load('user'))], 201);
    }

    private function scopeAdmin(Request $request, $query): void
    {
        $query->where('province_id', $request->user()->province_id ?? 0);

        if ($municipalityId = $request->query('municipality_id')) {
            $query->where('municipality_id', $municipalityId);
        }
    }

    private function scopeSuperAdmin(Request $request, $query): void
    {
        if ($provinceId = $request->query('province_id')) {
            $query->where('province_id', $provinceId);
        }

        if ($municipalityId = $request->query('municipality_id')) {
            $query->where('municipality_id', $municipalityId);
        }
    }
}
