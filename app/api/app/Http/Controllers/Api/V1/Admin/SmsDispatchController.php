<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SmsDispatchStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Models\SmsDispatch;
use App\Models\User;
use App\Services\Sms\KambaSmsProvider;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Histórico de SMS enviados pela Central de Operações e estatísticas
 * gerais (secções 59-60). Nunca mistura "enviado" com "entregue" —
 * são estados distintos e assim se mantêm em toda a interface.
 */
class SmsDispatchController extends Controller
{
    /**
     * VISIBILIDADE (Setembro 2026, pedido explícito do cliente —
     * monitorização/auditoria de custos): um ADMIN comum só vê os SMS
     * que ele próprio enviou; só o SUPER_ADMIN vê o histórico de todos
     * os admins (e pode filtrar por `created_by`). Sem isto, qualquer
     * Admin conseguia ver o conteúdo de mensagens enviadas por outros
     * admins a clientes de outras províncias — mais do que o necessário
     * para o seu próprio trabalho, e incompatível com o objetivo de
     * auditoria (o controlo só faz sentido se não for visível/editável
     * por quem está a ser controlado).
     */
    private function scopeToOwnDispatchesUnlessSuperAdmin(Request $request, $query): void
    {
        if ($request->user()->role !== UserRole::SUPER_ADMIN) {
            $query->where('created_by', $request->user()->id);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = SmsDispatch::query()->with(['template', 'creator', 'operation.municipality']);

        $this->scopeToOwnDispatchesUnlessSuperAdmin($request, $query);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('recipient')) {
            $query->where('recipient', 'like', '%' . $request->string('recipient') . '%');
        }

        if ($request->filled('sms_template_id')) {
            $query->where('sms_template_id', $request->integer('sms_template_id'));
        }

        if ($request->filled('created_by') && $request->user()->role === UserRole::SUPER_ADMIN) {
            $query->where('created_by', $request->integer('created_by'));
        }

        if ($request->filled('municipality_id')) {
            $query->whereHas('operation', fn ($q) => $q->where('municipality_id', $request->integer('municipality_id')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        // BUG CORRIGIDO: `paginate()` devolvido diretamente aninha o array
        // real em `data.data` (com os metadados de paginação como irmãos),
        // não achatado como `{success, data: T[], meta: {...}}` — a mesma
        // convenção usada em todos os outros endpoints paginados admin
        // (ex: StationAdminController::index()). O frontend espera
        // `Paginated<T>` achatado, e `HistoryPanel` fazia
        // `historyQuery.data?.data.map(...)`, que rebentava com
        // "TypeError: ... .map is not a function" porque `data` era o
        // objeto paginador do Laravel, não um array.
        $dispatches = $query->latest()->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $dispatches->items(),
            'meta' => [
                'current_page' => $dispatches->currentPage(),
                'last_page' => $dispatches->lastPage(),
                'total' => $dispatches->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $base = SmsDispatch::query();

        $this->scopeToOwnDispatchesUnlessSuperAdmin($request, $base);

        if ($request->filled('date_from')) {
            $base->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $base->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $total = (clone $base)->count();
        $sent = (clone $base)->whereIn('status', [SmsDispatchStatus::SENT, SmsDispatchStatus::DELIVERED])->count();
        $delivered = (clone $base)->where('status', SmsDispatchStatus::DELIVERED)->count();
        $failed = (clone $base)->where('status', SmsDispatchStatus::FAILED)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_sent' => $sent,
                'total_delivered' => $delivered,
                'total_failed' => $failed,
                'success_rate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0,
                'operations_processed' => Operation::count(),
                'most_consulted_municipalities' => Operation::query()
                    ->selectRaw('municipality_id, count(*) as total')
                    ->whereNotNull('municipality_id')
                    ->groupBy('municipality_id')
                    ->orderByDesc('total')
                    ->with('municipality:id,name')
                    ->limit(5)
                    ->get()
                    ->map(fn ($row) => ['municipality' => $row->municipality?->name, 'total' => $row->total]),
            ],
        ]);
    }

    /**
     * Saldo de créditos do provedor de SMS (pedido explícito do
     * cliente, Setembro 2026) — só faz sentido com `SMS_PROVIDER=kamba`
     * (a Kamba é o único provedor com noção de "créditos"; `mock` e
     * `production` (o antigo, genérico) não têm este conceito). Alerta
     * antes de ficar sem saldo em vez de descobrir a meio de um envio
     * real a um cliente (erro 402), ver `KambaSmsProvider::creditsBalance()`.
     *
     * ESTRITAMENTE SUPER_ADMIN (pedido explícito do cliente) — dado
     * financeiro da conta da plataforma junto do fornecedor, distinto
     * do `permission:sms.view` que Admin também tem para o histórico
     * de SMS. Verificado diretamente aqui (não via `permission:`),
     * mesmo padrão já usado para a correção de preço de entrega, que
     * também é uma exceção explícita "só Super Admin" ao mapa geral de
     * permissões.
     */
    public function credits(Request $request, SmsProviderInterface $provider): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::SUPER_ADMIN, 403);

        if (!$provider instanceof KambaSmsProvider) {
            return response()->json([
                'success' => true,
                'data' => [
                    'supported' => false,
                    'provider' => config('kerugas.sms.provider', 'mock'),
                    'balance' => null,
                    'message' => 'O provedor de SMS ativo (' . config('kerugas.sms.provider', 'mock') . ') não tem noção de créditos. Só disponível com SMS_PROVIDER=kamba.',
                ],
            ]);
        }

        $balance = $provider->creditsBalance();

        return response()->json([
            'success' => true,
            'data' => [
                'supported' => true,
                'provider' => 'kamba',
                'balance' => $balance,
                'message' => $balance === null ? 'Não foi possível consultar o saldo neste momento. Tente novamente.' : null,
            ],
        ]);
    }

    /**
     * Uso de SMS por Admin (Setembro 2026, pedido explícito do cliente
     * — controlo de custo/auditoria): quantos SMS cada Admin enviou
     * hoje e este mês, face ao seu limite diário configurado (ver
     * `sms_daily_limit` em `users`, editável em /admin/users). ESTRITA-
     * MENTE SUPER_ADMIN — mesmo padrão de `credits()`: um Admin nunca
     * deve ver quanto os colegas enviaram, senão o controlo perde o
     * sentido de auditoria.
     *
     * Contagens agregadas numa única query por período (em vez de N+1
     * por admin) e depois combinadas em memória — o número de Admins é
     * sempre pequeno (dezenas, não milhares), por isso não há razão
     * para complicar com uma única query com múltiplos JOINs.
     */
    public function usageByAdmin(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::SUPER_ADMIN, 403);

        $admins = User::query()
            ->where('role', UserRole::ADMIN)
            ->with('province')
            ->orderBy('name')
            ->get(['id', 'name', 'province_id', 'sms_daily_limit']);

        $todayCounts = SmsDispatch::query()
            ->whereDate('created_at', now()->toDateString())
            ->where('status', '!=', SmsDispatchStatus::FAILED)
            ->selectRaw('created_by, count(*) as total')
            ->groupBy('created_by')
            ->pluck('total', 'created_by');

        $monthCounts = SmsDispatch::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->where('status', '!=', SmsDispatchStatus::FAILED)
            ->selectRaw('created_by, count(*) as total')
            ->groupBy('created_by')
            ->pluck('total', 'created_by');

        $lastSentAt = SmsDispatch::query()
            ->selectRaw('created_by, max(created_at) as last_sent_at')
            ->groupBy('created_by')
            ->pluck('last_sent_at', 'created_by');

        $data = $admins->map(function (User $admin) use ($todayCounts, $monthCounts, $lastSentAt) {
            $sentToday = (int) ($todayCounts[$admin->id] ?? 0);

            return [
                'id' => $admin->id,
                'name' => $admin->name,
                'province' => $admin->province?->name,
                'daily_limit' => $admin->sms_daily_limit,
                'sent_today' => $sentToday,
                'remaining_today' => $admin->sms_daily_limit !== null ? max(0, $admin->sms_daily_limit - $sentToday) : null,
                'sent_this_month' => (int) ($monthCounts[$admin->id] ?? 0),
                'last_sent_at' => $lastSentAt[$admin->id] ?? null,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }
}
