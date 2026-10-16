<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Erros do site e do app (Outubro 2026) — só o Super Admin geral. */
class ClientErrorAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');

        $errors = ClientError::query()
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when(in_array($request->query('source'), ['web', 'android', 'ios'], true), fn ($q) => $q->where('source', $request->query('source')))
            ->orderByDesc('last_seen_at')
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => collect($errors->items())->map(fn (ClientError $e) => [
                'id' => $e->id,
                'source' => $e->source,
                'name' => $e->name,
                'message' => $e->message,
                'stack' => $e->stack,
                'url_path' => $e->url_path,
                'app_version' => $e->app_version,
                'user_agent' => $e->user_agent,
                'occurrences' => $e->occurrences,
                'first_seen_at' => $e->first_seen_at?->toIso8601String(),
                'last_seen_at' => $e->last_seen_at?->toIso8601String(),
                'resolved_at' => $e->resolved_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $errors->currentPage(), 'last_page' => $errors->lastPage(), 'total' => $errors->total()],
        ]);
    }

    public function resolve(ClientError $clientError): JsonResponse
    {
        $clientError->forceFill(['resolved_at' => $clientError->resolved_at ? null : now()])->save();

        return response()->json(['success' => true, 'data' => ['id' => $clientError->id, 'resolved_at' => $clientError->resolved_at?->toIso8601String()]]);
    }
}
