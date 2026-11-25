<?php

namespace App\Http\Controllers\Api\V1\Community;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommunityBannedWord;
use App\Models\CommunityMessage;
use App\Models\CommunityMute;
use App\Models\Municipality;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use App\Services\Community\CommunityPresenter;
use App\Services\Community\CommunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Moderação dos Vizinhos KeruGás (Outubro 2026). A rota só deixa entrar a
 * equipa (Moderador, Admin, PSA, Super Admin); o âmbito de cada pessoa
 * (país, província ou nada) é aplicado em CommunityAccess.
 */
class CommunityModerationController extends Controller
{
    public function __construct(
        private readonly CommunityService $service,
        private readonly CommunityPresenter $presenter,
    ) {
    }

    /** Lista para o painel: denunciadas, em revisão, removidas, apagadas pelo autor, todas. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tab = (string) $request->query('tab', 'reported');
        $ids = CommunityAccess::viewableMunicipalityIds($user);

        $q = CommunityMessage::query()->with('municipality:id,name,province_id', 'municipality.province:id,name')
            ->when($ids !== null, fn ($x) => $x->whereIn('municipality_id', $ids))
            ->when($request->integer('municipality_id'), fn ($x, $m) => $x->where('municipality_id', $m));

        match ($tab) {
            'reported' => $q->whereNull('removed_at')->whereNull('author_deleted_at')
                ->whereHas('reports', fn ($r) => $r->whereNull('resolved_at')),
            'hidden' => $q->whereNotNull('hidden_at')->whereNull('removed_at'),
            'removed' => $q->whereNotNull('removed_at'),
            'deleted' => $q->whereNotNull('author_deleted_at'),
            default => null,
        };

        $page = $q->withCount(['reports as open_reports_count' => fn ($r) => $r->whereNull('resolved_at')])
            ->orderByDesc('id')->paginate(20);
        $presented = $this->presenter->present(collect($page->items()), $user);

        $data = collect($page->items())->values()->map(fn (CommunityMessage $m, int $i) => $presented[$i] + [
            'municipality' => $m->municipality?->name,
            'province' => $m->municipality?->province?->name,
            'province_id' => $m->municipality?->province_id,
            'open_reports' => (int) $m->open_reports_count,
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function reports(Request $request, CommunityMessage $message): JsonResponse
    {
        abort_unless(CommunityAccess::canModerate($request->user(), (int) $message->municipality_id), 403);

        $rows = $message->reports()->with('reporter:id,name')->latest()->get()->map(fn ($r) => [
            'id' => $r->id, 'reason' => $r->reason, 'details' => $r->details,
            'reporter' => $r->reporter?->name, 'resolved' => $r->resolved_at !== null, 'created_at' => $r->created_at?->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function remove(Request $request, CommunityMessage $message): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->service->remove($request->user(), $message, $data['reason']);

        return response()->json(['success' => true]);
    }

    public function restore(Request $request, CommunityMessage $message): JsonResponse
    {
        $this->service->restore($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function dismiss(Request $request, CommunityMessage $message): JsonResponse
    {
        $this->service->dismissReports($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, CommunityMessage $message): JsonResponse
    {
        $this->service->hardDelete($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function pin(Request $request, CommunityMessage $message): JsonResponse
    {
        $this->service->pin($request->user(), $message, true);

        return response()->json(['success' => true]);
    }

    public function unpin(Request $request, CommunityMessage $message): JsonResponse
    {
        $this->service->pin($request->user(), $message, false);

        return response()->json(['success' => true]);
    }

    // ── Silenciamentos ──────────────────────────────────────────────────

    public function mutes(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = CommunityAccess::viewableMunicipalityIds($user);

        $rows = CommunityMute::query()->active()
            ->whereHas('user', fn ($u) => $u->when($ids !== null, fn ($x) => $x->whereIn('municipality_id', $ids)))
            ->with('user:id,name,municipality_id')->latest('id')->limit(100)->get()
            ->map(fn ($m) => [
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

    // ── Estatísticas (Admin para cima) ─────────────────────────────────

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(CommunityAccess::canAdminister($user), 403);
        $ids = CommunityAccess::viewableMunicipalityIds($user);

        $base = fn () => CommunityMessage::query()->when($ids !== null, fn ($q) => $q->whereIn('municipality_id', $ids));

        $perDay = $base()->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) d, COUNT(*) c')->groupBy('d')->orderBy('d')->pluck('c', 'd');

        $top = $base()->where('created_at', '>=', now()->subDays(30))->selectRaw('municipality_id, COUNT(*) c')
            ->groupBy('municipality_id')->orderByDesc('c')->limit(5)->get();
        $names = Municipality::query()->whereIn('id', $top->pluck('municipality_id'))->pluck('name', 'id');

        return response()->json(['success' => true, 'data' => [
            'total' => $base()->count(),
            'last_7_days' => $base()->where('created_at', '>=', now()->subDays(7))->count(),
            'open_reports' => $base()->whereHas('reports', fn ($r) => $r->whereNull('resolved_at'))->whereNull('removed_at')->count(),
            'removed' => $base()->whereNotNull('removed_at')->count(),
            'per_day' => collect(range(13, 0))->map(fn ($i) => ['day' => now()->subDays($i)->toDateString(), 'count' => (int) ($perDay[now()->subDays($i)->toDateString()] ?? 0)])->values(),
            'top_municipalities' => $top->map(fn ($r) => ['name' => $names[$r->municipality_id] ?? '—', 'count' => (int) $r->c])->values(),
        ]]);
    }

    // ── Só Super Admin: palavras proibidas e ligar/desligar por município ──

    public function bannedWords(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => CommunityBannedWord::query()->orderBy('word')->get(['id', 'word'])]);
    }

    public function addBannedWord(Request $request): JsonResponse
    {
        $data = $request->validate(['word' => ['required', 'string', 'min:2', 'max:60']]);
        $word = Str::lower(trim($data['word']));
        $row = CommunityBannedWord::query()->firstOrCreate(['word' => $word], ['created_by' => $request->user()->id]);

        return response()->json(['success' => true, 'data' => ['id' => $row->id, 'word' => $row->word]], 201);
    }

    public function removeBannedWord(CommunityBannedWord $word): JsonResponse
    {
        $word->delete();

        return response()->json(['success' => true]);
    }

    public function toggleMunicipality(Request $request, Municipality $municipality): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $municipality->forceFill(['community_enabled' => $data['enabled']])->save();

        return response()->json(['success' => true, 'data' => ['id' => $municipality->id, 'enabled' => (bool) $municipality->community_enabled]]);
    }

    public function municipalities(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::SUPER_ADMIN, 403);

        return response()->json(['success' => true, 'data' => Municipality::query()->with('province:id,name')->orderBy('province_id')->orderBy('name')->get()
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'province' => $m->province?->name, 'enabled' => (bool) $m->community_enabled])]);
    }
}
