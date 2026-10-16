<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\MunicipalityInvestment;
use App\Models\RoomMessage;
use App\Models\User;
use App\Services\Investors\InvestorService;
use App\Services\Media\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestão dos investidores (Outubro 2026) — SÓ o Super Admin geral (a rota
 * já o exige e o serviço volta a confirmar).
 */
class InvestorAdminController extends Controller
{
    public function __construct(private readonly InvestorService $investors)
    {
    }

    /** Investidores com os municípios em vigor, e que municípios já têm investidor. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $active = MunicipalityInvestment::query()->active()->with(['municipality.province:id,name', 'investor'])->orderBy('starts_at')->get();

        $people = User::query()->where('role', UserRole::INVESTOR)->orWhereIn('id', $active->pluck('user_id'))->orderBy('name')->get();
        $reads = DB::table('room_reads')->where('user_id', $actor->id)->where('room_type', RoomMessage::INVESTOR)->pluck('last_read_id', 'room_id');

        $paused = MunicipalityInvestment::query()->where('end_reason', 'deactivated')
            ->whereIn('user_id', $people->whereNotNull('investor_deactivated_at')->pluck('id'))
            ->with('municipality:id,name')->get()->groupBy('user_id');

        $investors = $people->map(function (User $u) use ($active, $reads, $paused) {
            $mine = $active->where('user_id', $u->id)->values();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email,
                'avatar_url' => AvatarService::url($u->avatar_path),
                'status' => $u->status,
                // Desativado (contrato desfeito): sem acesso à faturação; municípios libertados.
                'active' => $u->investor_deactivated_at === null,
                'deactivated_at' => $u->investor_deactivated_at?->toIso8601String(),
                'paused_municipalities' => $u->investor_deactivated_at
                    ? $paused->get($u->id, collect())->filter(fn ($r) => $r->ends_at && $r->ends_at->gte($u->investor_deactivated_at->copy()->subMinute()))
                        ->map(fn ($r) => ['id' => (int) $r->municipality_id, 'name' => $r->municipality?->name, 'percentage' => $r->percentage])->values()
                    : [],
                'municipalities' => $mine->map(fn (MunicipalityInvestment $a) => [
                    'id' => (int) $a->municipality_id,
                    'name' => $a->municipality?->name,
                    'province' => $a->municipality?->province?->name,
                    'percentage' => $a->percentage,
                    'since' => $a->starts_at->toIso8601String(),
                ])->values(),
                'chat_unread' => RoomMessage::query()->room(RoomMessage::INVESTOR, $u->id)->visible()
                    ->where('user_id', $u->id)->where('id', '>', (int) ($reads[$u->id] ?? 0))->count(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => [
            'investors' => $investors,
            // Para o formulário: municípios ocupados não se podem escolher.
            'taken' => $active->map(fn (MunicipalityInvestment $a) => [
                'municipality_id' => (int) $a->municipality_id,
                'investor_id' => (int) $a->user_id,
                'investor_name' => $a->investor?->name,
            ])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $investor = User::query()->findOrFail($data['user_id']);
        $row = $this->investors->assign($request->user(), $investor, (int) $data['municipality_id'], (float) $data['percentage'], $data['starts_on'] ?? null);

        return response()->json(['success' => true, 'data' => ['id' => $row->id, 'percentage' => $row->percentage]], 201);
    }

    public function update(Request $request, User $investor, Municipality $municipality): JsonResponse
    {
        $data = $request->validate(['percentage' => ['required', 'numeric', 'min:0.01', 'max:100']]);
        $row = $this->investors->changePercentage($request->user(), $investor, $municipality->id, (float) $data['percentage']);

        return response()->json(['success' => true, 'data' => ['id' => $row->id, 'percentage' => $row->percentage]]);
    }

    public function destroy(Request $request, User $investor, Municipality $municipality): JsonResponse
    {
        $this->investors->remove($request->user(), $investor, $municipality->id);

        return response()->json(['success' => true]);
    }

    /** Exportar a faturação de um investidor ativo (Excel ou PDF). */
    public function export(Request $request, User $investor, \App\Services\Billing\BillingReportService $reports)
    {
        $data = $request->validate([
            'format' => ['required', 'in:csv,pdf'],
            'municipality_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        abort_unless($investor->role === UserRole::INVESTOR, 404, 'Esta conta não é de investidor.');
        $report = $this->investors->report($investor, isset($data['municipality_id']) ? (int) $data['municipality_id'] : null, $data['from'] ?? null, $data['to'] ?? null);

        \App\Models\AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'investor.exported', 'entity_type' => User::class, 'entity_id' => $investor->id,
            'new_values' => ['format' => $data['format'], 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'municipality_id' => $data['municipality_id'] ?? null],
        ]);

        try {
            return $reports->download($report, $data['format']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** Desativar (contrato desfeito) — termina os períodos e liberta os municípios. */
    public function deactivate(Request $request, User $investor): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $freed = $this->investors->deactivate($request->user(), $investor, $data['reason'] ?? null);

        return response()->json(['success' => true, 'data' => ['freed_municipalities' => $freed]]);
    }

    /** Reativar — volta aos municípios que ficaram livres, a partir de agora. */
    public function reactivate(Request $request, User $investor): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->investors->reactivate($request->user(), $investor)]);
    }

    /** O Super Admin vê exatamente o mesmo que o investidor vê. */
    public function overview(Request $request, User $investor): JsonResponse
    {
        $data = $request->validate([
            'municipality_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['success' => true, 'data' => $this->investors->overview(
            $investor,
            isset($data['municipality_id']) ? (int) $data['municipality_id'] : null,
            $data['from'] ?? null,
            $data['to'] ?? null,
        )]);
    }
}
