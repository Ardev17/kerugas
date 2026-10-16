<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\StationCommentResource;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comentários de postos (secção 34). Entram como PENDING por omissão
 * e só ficam VISIBLE após moderação — protege contra spam e conteúdo
 * impróprio antes de chegar a outros utilizadores (secção 99).
 */
class StationCommentController extends Controller
{
    public function index(Station $station): JsonResponse
    {
        $comments = $station->comments()
            ->where('status', CommentStatus::VISIBLE)
            ->with('user')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => StationCommentResource::collection($comments),
            'meta' => ['current_page' => $comments->currentPage(), 'last_page' => $comments->lastPage()],
        ]);
    }

    public function store(StoreCommentRequest $request, Station $station): JsonResponse
    {
        $comment = $station->comments()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'status' => CommentStatus::PENDING,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Comentário enviado e aguarda moderação.',
            'data' => new StationCommentResource($comment->load('user')),
        ], 201);
    }
}
