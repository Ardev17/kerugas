<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StationCommentResource;
use App\Models\AuditLog;
use App\Models\StationComment;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Fila de moderação de comentários (secção 39/99). Por omissão mostra
 * apenas PENDING — o moderador decide aprovar, ocultar ou eliminar.
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só vê e
 * só consegue moderar comentários de postos DA SUA província.
 * `StationComment` não tem `province_id` próprio — a província vem
 * sempre do posto comentado (`station.province_id`), daí o
 * `whereHas`/`$comment->station->province_id` em vez de um `where`
 * direto. SUPER_ADMIN e MODERATOR mantêm-se sem restrição (o pedido do
 * cliente falou apenas de "administrador" vs "super administrador").
 */
class CommentModerationController extends Controller
{
    use ScopesToAdminProvince;

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', CommentStatus::PENDING->value);

        $query = StationComment::query()
            ->where('status', $status)
            ->with(['user', 'station']);

        if ($this->isProvinceScopedAdmin($request)) {
            $provinceId = $this->adminProvinceId($request);
            $query->whereHas('station', fn ($q) => $q->where('province_id', $provinceId));
        }

        $comments = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => StationCommentResource::collection($comments),
            'meta' => ['current_page' => $comments->currentPage(), 'last_page' => $comments->lastPage()],
        ]);
    }

    public function updateStatus(Request $request, StationComment $comment): JsonResponse
    {
        $this->assertProvinceAccess($request, $comment);

        $request->validate(['status' => ['required', new Enum(CommentStatus::class)]]);

        $oldStatus = $comment->status->value;

        $comment->update(['status' => $request->input('status')]);

        // AUDITORIA DE SEGURANÇA (Setembro 2026): esta ação não deixava
        // nenhum rasto — se uma conta de Admin/Moderador fosse
        // comprometida, ela podia aprovar/ocultar comentários à vontade
        // sem ninguém conseguir saber quem fez o quê depois.
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'comment.status_updated',
            'entity_type' => StationComment::class,
            'entity_id' => $comment->id,
            'old_values' => ['status' => $oldStatus],
            'new_values' => ['status' => $comment->status->value],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => new StationCommentResource($comment)]);
    }

    private function assertProvinceAccess(Request $request, StationComment $comment): void
    {
        if (!$this->isProvinceScopedAdmin($request)) {
            return;
        }

        if ((int) $comment->station?->province_id !== $this->adminProvinceId($request)) {
            abort(403, 'Este comentário pertence a um posto de outra província — fora do âmbito da sua conta de administrador.');
        }
    }
}
