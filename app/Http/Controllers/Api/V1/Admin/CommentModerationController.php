<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StationCommentResource;
use App\Models\AuditLog;
use App\Models\StationComment;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use App\Support\LocationGroups;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Organização por categoria → província → município (Outubro 2026):
     * contagens para as abas e para as secções do painel. Mesma consulta e
     * mesmo âmbito da lista.
     */
    public function groups(Request $request): JsonResponse
    {
        $counts = $this->listQuery($request, withStatus: false)->reorder()
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $tabs = collect([CommentStatus::PENDING, CommentStatus::VISIBLE, CommentStatus::HIDDEN])->map(fn (CommentStatus $s) => [
            'key' => $s->value,
            'count' => (int) ($counts[$s->value] ?? 0),
        ])->values();

        return response()->json(['success' => true, 'data' => [
            'tabs' => $tabs,
            'groups' => LocationGroups::byStation($this->listQuery($request, withStatus: true)),
        ]]);
    }

    private function listQuery(Request $request, bool $withStatus): Builder
    {
        $query = StationComment::query();

        if ($withStatus) {
            $query->where('status', $request->query('status', CommentStatus::PENDING->value));
        }

        if ($this->isProvinceScopedAdmin($request)) {
            $provinceId = $this->adminProvinceId($request);
            $query->whereHas('station', fn ($q) => $q->where('province_id', $provinceId));
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery($request, withStatus: true)->with(['user', 'station.municipality', 'station.province']);
        LocationGroups::filterStation($query, $request);

        $comments = $query->latest()->latest('id')->paginate(20);

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
