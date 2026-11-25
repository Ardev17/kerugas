<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * As avaliações do próprio condutor (Outubro 2026).
 *
 * PRIVACIDADE DE QUEM AVALIA: o condutor conhece a morada de cada cliente.
 * Por isso cada avaliação mostra só as estrelas, o comentário e o DIA —
 * nunca o nome, o telefone, a referência do pedido nem a hora. Assim o
 * condutor aprende com o que dizem dele, sem conseguir associar uma má
 * avaliação a uma porta concreta.
 */
class DriverRatingsController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        $driverId = $request->user()->id;
        $stars = $request->integer('stars');
        $stars = $stars >= 1 && $stars <= 5 ? $stars : null;

        $page = DriverRating::query()
            ->where('driver_id', $driverId)
            ->when($stars, fn ($q) => $q->where('rating', $stars))
            ->latest('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'rating', 'comment', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (DriverRating $r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => filled($r->comment) ? $r->comment : null,
                'date' => $r->created_at?->toDateString(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'summary' => $this->summary($driverId),
        ]);
    }

    /** Média, total, estrelas de 5 a 1 e a tendência dos últimos 30 dias. */
    private function summary(int $driverId): array
    {
        $base = DriverRating::query()->where('driver_id', $driverId);

        $distribution = (clone $base)->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $distribution->sum();

        $recent = (clone $base)->where('created_at', '>=', now()->subDays(30));
        $recentCount = (clone $recent)->count();

        return [
            'average' => $count > 0 ? round((float) (clone $base)->avg('rating'), 2) : null,
            'count' => $count,
            'with_comment' => (clone $base)->whereNotNull('comment')->where('comment', '!=', '')->count(),
            // sempre as 5 chaves, da melhor para a pior
            'distribution' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [(string) $s => (int) ($distribution[$s] ?? 0)]),
            'last_30_days' => [
                'average' => $recentCount > 0 ? round((float) $recent->avg('rating'), 2) : null,
                'count' => $recentCount,
            ],
        ];
    }
}
