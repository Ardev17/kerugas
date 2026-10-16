<?php

namespace App\Http\Controllers\Api\V1\Community;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommunityBlock;
use App\Models\CommunityMessage;
use App\Models\CommunityReaction;
use App\Models\CommunityReport;
use App\Models\Municipality;
use App\Models\Neighborhood;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use App\Services\Community\CommunityLocationService;
use App\Services\Community\CommunityPresence;
use App\Services\Community\CommunityPresenter;
use App\Services\Community\CommunityService;
use App\Services\Media\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vizinhos KeruGás (Outubro 2026) — a comunidade de cada município.
 * Independente dos comentários dos postos e do chat de condutores/admins.
 */
class CommunityController extends Controller
{
    public const WINDOW = 30;

    public function __construct(
        private readonly CommunityService $service,
        private readonly CommunityPresenter $presenter,
        private readonly CommunityPresence $presence,
        private readonly CommunityLocationService $locations,
    ) {
    }

    /** Município pedido (ou o da pessoa) — já com a verificação de acesso. */
    private function municipalityId(Request $request): int
    {
        $user = $request->user();
        $id = $request->integer('municipality_id') ?: CommunityAccess::defaultMunicipalityId($user);

        if (!$id) {
            abort(response()->json([
                'success' => false, 'code' => 'needs_municipality',
                'message' => 'Escolha o seu município no perfil para entrar nos Vizinhos KeruGás.',
            ], 422));
        }

        $this->service->assertCanView($user, $id);

        return $id;
    }

    /** Estado da pessoa na comunidade: onde está, o que pode fazer, bairros, quem está ativo. */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $mid = $this->municipalityId($request);
        $municipality = Municipality::with('province:id,name')->findOrFail($mid);
        $mute = $this->service->activeMute($user);
        $presence = $this->presence->counts($mid);

        $switch = [];
        if (!in_array($user->role, [UserRole::USER, UserRole::DRIVER, UserRole::STATION_OPERATOR], true)) {
            $ids = CommunityAccess::viewableMunicipalityIds($user);
            $switch = Municipality::query()->with('province:id,name')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
                ->orderBy('province_id')->orderBy('name')->get()
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'province' => $m->province?->name, 'province_id' => $m->province_id])->all();
        }

        return response()->json(['success' => true, 'data' => [
            'municipality' => ['id' => $municipality->id, 'name' => $municipality->name, 'province' => $municipality->province?->name, 'enabled' => (bool) $municipality->community_enabled],
            'neighborhoods' => Neighborhood::query()->where('municipality_id', $mid)->orderBy('name')->get(['id', 'name']),
            'topics' => collect(CommunityService::TOPICS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'report_reasons' => collect(CommunityReport::REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'emojis' => CommunityReaction::EMOJIS,
            'me' => [
                'accepted_rules' => $user->community_accepted_at !== null,
                'neighborhood_id' => $user->neighborhood_id,
                'can_write' => CommunityAccess::canWrite($user, $mid) && (bool) $municipality->community_enabled && $mute === null,
                'writes_as_team' => CommunityAccess::writesAsTeam($user, $mid),
                'muted_until' => $mute?->expires_at?->toIso8601String(),
                'muted' => $mute !== null,
                'is_staff' => CommunityAccess::canModerate($user, $mid),
                'can_administer' => CommunityAccess::canAdminister($user),
                'is_super' => $user->role === UserRole::SUPER_ADMIN,
                'max_mute_hours' => CommunityAccess::maxMuteHours($user),
            ],
            'limits' => ['max_body' => CommunityService::MAX_BODY, 'edit_minutes' => CommunityService::EDIT_MINUTES, 'max_mentions' => CommunityService::MAX_MENTIONS],
            'switch_municipalities' => $switch,
            'presence' => ['online' => $presence['online'], 'active_today' => $presence['active_today']],
            // Partilhar a localização: só de dia (os postos fecham às 18h).
            'location_share' => $this->locations->window(),
        ]]);
    }

    public function acceptRules(Request $request): JsonResponse
    {
        $request->user()->forceFill(['community_accepted_at' => $request->user()->community_accepted_at ?? now()])->save();

        return response()->json(['success' => true]);
    }

    /**
     * Janela de mensagens (30 de cada vez), da mais antiga para a mais recente.
     * Sem cursor: as mais recentes. `before_id`: mais antigas que esse id.
     * `after_id`: mais novas. `around_id`: a janela à volta de uma mensagem (menções).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mid = $this->municipalityId($request);
        $request->validate([
            'topic' => ['nullable', Rule::in(array_keys(CommunityService::TOPICS))],
            'neighborhood_id' => ['nullable', 'integer'],
            'before_id' => ['nullable', 'integer'], 'after_id' => ['nullable', 'integer'], 'around_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $staff = CommunityAccess::canModerate($user, $mid);
        $base = fn () => $this->scopedQuery($request, $mid, $staff);
        $limit = self::WINDOW;

        if ($around = $request->integer('around_id')) {
            $target = $base()->whereKey($around)->first(['id']);
            if (!$target) {
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
                'oldest_id' => $oldest, 'newest_id' => $newest,
                'municipality_id' => $mid,
            ],
        ];

        // Avisos fixados: só na janela inicial (ou à volta de uma mensagem), sempre do município inteiro.
        if (!$request->filled('before_id') && !$request->filled('after_id')) {
            $pinned = CommunityMessage::query()->visible()->where('municipality_id', $mid)->whereNotNull('pinned_at')
                ->orderByDesc('pinned_at')->limit(3)->get();
            $payload['pinned'] = $this->presenter->present($pinned, $user);
        }

        return response()->json($payload);
    }

    private function scopedQuery(Request $request, int $mid, bool $staff)
    {
        $user = $request->user();
        $q = CommunityMessage::query()->where('municipality_id', $mid);

        if (!$staff) {
            $q->whereNull('author_deleted_at');
        }

        $blocked = CommunityBlock::query()->where('user_id', $user->id)->pluck('blocked_user_id');
        if ($blocked->isNotEmpty()) {
            $q->whereNotIn('user_id', $blocked);
        }

        if ($topic = $request->query('topic')) {
            $q->where('topic', $topic);
        }
        if ($nb = $request->integer('neighborhood_id')) {
            $q->where('neighborhood_id', $nb);
        }
        if ($request->boolean('mine')) {
            $q->where('user_id', $user->id);
        }
        if ($request->boolean('mentions_me')) {
            $q->whereHas('mentions', fn ($m) => $m->where('user_id', $user->id));
        }
        if ($request->boolean('staff_only')) {
            $q->where(fn ($s) => $s->where('official', true)->orWhereHas('user', fn ($u) => $u->whereIn('role', array_map(fn ($r) => $r->value, CommunityAccess::staffRoles()))));
        }
        if (($text = trim((string) $request->query('q'))) !== '') {
            $q->where('body', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%');
        }

        return $q;
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $mid = $this->municipalityId($request);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:2000'],
            'topic' => ['nullable', Rule::in(array_keys(CommunityService::TOPICS))],
            'neighborhood_id' => ['nullable', 'integer'],
            'reply_to_id' => ['nullable', 'integer'],
            'image' => ['nullable', 'file', 'max:5120'],
        ]);

        $message = $this->service->post($user, $mid, $data, $request->file('image'));

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $user)[0]], 201);
    }

    /**
     * "Partilhar a minha localização" (Outubro 2026): um ponto (posição do
     * telemóvel) ou um posto, por 30 min, 1 h ou 2 h. Sem texto.
     */
    public function shareLocation(Request $request): JsonResponse
    {
        $user = $request->user();
        $mid = $this->municipalityId($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(CommunityLocationService::KINDS)],
            // Angola, com Cabinda (com margem): nada de coordenadas do outro lado do mundo.
            'latitude' => ['required', 'numeric', 'between:-18.2,-4.2'],
            'longitude' => ['required', 'numeric', 'between:11.5,24.2'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'station_id' => ['required_if:kind,station', 'nullable', 'integer'],
            'minutes' => ['required', 'integer'],
        ], [
            'latitude.between' => 'Essa localização não é em Angola.',
            'longitude.between' => 'Essa localização não é em Angola.',
        ]);

        $message = $this->locations->share($user, $mid, $data);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $user)[0]], 201);
    }

    /** Parar de partilhar antes do tempo — só quem partilhou. */
    public function stopLocation(Request $request, CommunityMessage $message): JsonResponse
    {
        $message = $this->locations->stop($request->user(), $message);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message]), $request->user())[0]]);
    }

    /** Ids das partilhas ainda ativas — o ecrã tira o mapa das que já acabaram. */
    public function activeLocations(Request $request): JsonResponse
    {
        $mid = $this->municipalityId($request);

        return response()->json(['success' => true, 'data' => $this->locations->activeIds($mid)]);
    }

    public function update(Request $request, CommunityMessage $message): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $this->service->assertCanView($request->user(), (int) $message->municipality_id);
        $message = $this->service->edit($request->user(), $message, $data['body']);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    public function destroy(Request $request, CommunityMessage $message): JsonResponse
    {
        $this->service->authorDelete($request->user(), $message);

        return response()->json(['success' => true]);
    }

    public function react(Request $request, CommunityMessage $message): JsonResponse
    {
        $data = $request->validate(['emoji' => ['nullable', 'string', Rule::in(CommunityReaction::EMOJIS)]]);
        $this->service->react($request->user(), $message, $data['emoji'] ?? null);

        return response()->json(['success' => true, 'data' => $this->presenter->present(collect([$message->fresh()]), $request->user())[0]]);
    }

    public function report(Request $request, CommunityMessage $message): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(CommunityReport::REASONS))],
            'details' => ['nullable', 'string', 'max:300'],
        ]);
        $this->service->report($request->user(), $message, $data['reason'], $data['details'] ?? null);

        return response()->json(['success' => true, 'message' => 'Obrigado. A moderação vai analisar esta mensagem.']);
    }

    /** Sugestões de @: pessoas do mesmo município (nome e bairro; nunca o número). */
    public function mentionable(Request $request): JsonResponse
    {
        $mid = $this->municipalityId($request);
        $term = trim((string) $request->query('q', ''));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        $people = $this->service->mentionable($mid)->where('id', '!=', $request->user()->id)
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%' . $escaped . '%'))
            ->with('neighborhood:id,name')->orderBy('name')->limit(8)
            ->get(['id', 'name', 'role', 'avatar_path', 'neighborhood_id'])
            ->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'avatar_url' => AvatarService::url($u->avatar_path),
                'badge' => CommunityAccess::badge($u, false), 'neighborhood' => $u->neighborhood?->name,
            ]);

        return response()->json(['success' => true, 'data' => $people]);
    }

    public function presence(Request $request): JsonResponse
    {
        $mid = $this->municipalityId($request);

        return response()->json(['success' => true, 'data' => $this->presence->forMunicipality($mid, $request->user())]);
    }

    public function blocks(Request $request): JsonResponse
    {
        $rows = CommunityBlock::query()->where('user_id', $request->user()->id)->pluck('blocked_user_id');
        $people = User::query()->whereIn('id', $rows)->get(['id', 'name', 'avatar_path'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'avatar_url' => AvatarService::url($u->avatar_path)]);

        return response()->json(['success' => true, 'data' => $people]);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $this->service->block($request->user(), $user);

        return response()->json(['success' => true]);
    }

    public function unblock(Request $request, int $user): JsonResponse
    {
        $this->service->unblock($request->user(), $user);

        return response()->json(['success' => true]);
    }
}
