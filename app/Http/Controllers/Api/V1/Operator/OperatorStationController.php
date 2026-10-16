<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Enums\QueueLevel;
use App\Enums\StationUpdateSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportStationStatusRequest;
use App\Http\Resources\AdminStationResource;
use App\Services\StationStatusExportService;
use App\Services\StationStatusService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Painel do operador (secção 94/95). Um operador só vê e atualiza o
 * seu próprio posto — nunca dados de outros postos, mesmo por engano
 * de rota (verificação explícita, não apenas confiança no middleware).
 */
class OperatorStationController extends Controller
{
    public function __construct(
        private readonly StationStatusService $stationStatusService,
        private readonly StationStatusExportService $exportService,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        return response()->json([
            'success' => true,
            'data' => new AdminStationResource($station->load(['province', 'municipality'])),
        ]);
    }

    /**
     * GET /operator/station/subscription — a situação da subscrição do
     * posto e como pagar (IBAN e contacto para o comprovativo). Só mostra
     * dados do PRÓPRIO posto.
     */
    public function subscription(Request $request): JsonResponse
    {
        $station = $this->myStation($request);
        $settings = $this->subscriptions->settings();
        $active = $this->subscriptions->activeStationSubscription($station);

        return response()->json([
            'success' => true,
            'data' => [
                'analytics_enabled' => (bool) $station->analytics_enabled,
                'subscribed' => $active !== null,
                'valid_until' => $active?->expires_at->toIso8601String(),
                'payment' => [
                    'bank_name' => $settings->get('bank_name'),
                    'account_holder' => $settings->get('account_holder'),
                    'iban' => $settings->get('iban'),
                    'contact' => $settings->contact(),
                    'price' => $settings->priceLabel('price_analytics_kz') ?: null,
                ],
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        $history = $station->statuses()->latest()->limit(20)->get()->map(fn ($s) => [
            'gas_available' => $s->gas_available,
            'queue_level' => $s->queue_level->value,
            'source' => $s->source->value,
            'created_at' => $s->created_at->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => $history]);
    }

    /**
     * GET /operator/station/analytics — dashboard de dados do posto,
     * espelhando o que o Admin/Super Admin têm em /admin/analytics,
     * mas escopado apenas ao posto do operador autenticado.
     */
    public function analytics(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        abort_if(!$station->analytics_enabled, 403, 'O acesso ao analytics do seu posto foi desativado pelo Super Admin.');

        $since30d = now()->subDays(30);
        $since14d = now()->subDays(13)->startOfDay();

        $updates30d = $station->statuses()->where('created_at', '>=', $since30d)->get();
        $totalUpdates30d = $updates30d->count();

        $gasAvailablePercentage = $totalUpdates30d > 0
            ? round(($updates30d->where('gas_available', true)->count() / $totalUpdates30d) * 100)
            : null;

        $mostCommonQueueLevel = $updates30d
            ->groupBy(fn ($u) => $u->queue_level->value)
            ->sortByDesc(fn ($group) => $group->count())
            ->keys()
            ->first();

        $avgConfidence30d = $totalUpdates30d > 0
            ? round($updates30d->avg('confidence_score'))
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'total_updates_30d' => $totalUpdates30d,
                    'updates_today' => $station->statuses()->whereDate('created_at', today())->count(),
                    'current_confidence_score' => $station->current_confidence_score,
                    'avg_confidence_score_30d' => $avgConfidence30d,
                    'gas_available_percentage_30d' => $gasAvailablePercentage,
                    'most_common_queue_level' => $mostCommonQueueLevel,
                ],
                'updates_by_day' => $this->updatesByDay($station, $since14d),
                'confidence_trend' => $station->statuses()
                    ->latest()
                    ->limit(20)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn ($u) => [
                        'date' => $u->created_at->toIso8601String(),
                        'confidence_score' => $u->confidence_score,
                    ]),
                'gas_availability_ratio' => [
                    ['name' => 'Disponível', 'value' => $updates30d->where('gas_available', true)->count()],
                    ['name' => 'Indisponível', 'value' => $updates30d->where('gas_available', false)->count()],
                ],
                'queue_level_distribution' => [
                    ['name' => 'Fila baixa', 'value' => $updates30d->where('queue_level', QueueLevel::LOW)->count()],
                    ['name' => 'Fila média', 'value' => $updates30d->where('queue_level', QueueLevel::MEDIUM)->count()],
                    ['name' => 'Fila alta', 'value' => $updates30d->where('queue_level', QueueLevel::HIGH)->count()],
                ],
                'source_breakdown' => collect(StationUpdateSource::cases())->map(fn ($source) => [
                    'name' => $this->sourceLabel($source),
                    'value' => $updates30d->where('source', $source)->count(),
                ])->filter(fn ($row) => $row['value'] > 0)->values(),
            ],
        ]);
    }

    /**
     * GET /operator/station/export — exporta o histórico do PRÓPRIO
     * posto (Excel ou PDF), filtrado por dia/semana/mês.
     *
     * `station_id`/`municipality_id`/`province_id` do request são
     * sempre IGNORADOS aqui — um operador nunca pode exportar dados
     * de outro posto, mesmo que envie esses parâmetros manualmente.
     */
    public function export(ExportStationStatusRequest $request)
    {
        $station = $this->myStation($request);

        abort_if(!$station->analytics_enabled, 403, 'A exportação de dados do seu posto foi desativada pelo Super Admin.');

        return $this->exportService->export([
            ...$request->validated(),
            'station_id' => $station->id,
            'municipality_id' => null,
            'province_id' => null,
        ]);
    }

    /**
     * Contagem de atualizações por dia nos últimos 14 dias, com dias
     * sem atualização preenchidos a 0 — um gráfico de linha com
     * "buracos" confunde mais do que ajuda (mesma decisão já tomada
     * no AdminAnalyticsController).
     */
    private function updatesByDay($station, $since): array
    {
        $rows = $station->statuses()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $result = [];
        for ($i = 0; $i < 14; $i++) {
            $date = $since->copy()->addDays($i)->format('Y-m-d');
            $result[] = ['date' => $date, 'total' => (int) ($rows[$date] ?? 0)];
        }

        return $result;
    }

    private function sourceLabel(StationUpdateSource $source): string
    {
        return match ($source) {
            StationUpdateSource::OFFICIAL_SMS => 'SMS',
            StationUpdateSource::OPERATOR => 'Operador',
            StationUpdateSource::ADMIN => 'Admin',
            StationUpdateSource::COMMUNITY => 'Comunidade',
        };
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $station = $this->myStation($request);

        $data = $request->validate([
            'gas_available' => ['required', 'boolean'],
            'queue_level' => ['required', new Enum(QueueLevel::class)],
            // Tipos de garrafa disponíveis (secção nova) — nomes separados
            // por vírgula, ex: "garrafa leve, garrafa pequena, garrafa grande".
            // Só é escrito aqui e no update manual do Admin — o SMS nunca
            // altera este campo, por isso mantém sempre o valor da última
            // atualização feita pela web.
            'cylinder_types' => ['nullable', 'string', 'max:255'],
        ]);

        $this->stationStatusService->applyUpdate(
            $station,
            $data['gas_available'],
            QueueLevel::from($data['queue_level']),
            StationUpdateSource::OPERATOR,
            $request->user(),
        );

        if (array_key_exists('cylinder_types', $data) && $data['cylinder_types'] !== null) {
            $station->update(['current_cylinder_types' => $this->normalizeCylinderTypes($data['cylinder_types'])]);
        }

        return response()->json([
            'success' => true,
            'data' => new AdminStationResource($station->fresh(['province', 'municipality'])),
        ]);
    }

    /**
     * Normaliza "garrafa leve,  garrafa pequena ,garrafa grande" para
     * "garrafa leve, garrafa pequena, garrafa grande" — remove espaços
     * supérfluos em torno de cada nome sem alterar o que o utilizador
     * escreveu.
     */
    private function normalizeCylinderTypes(string $raw): string
    {
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, fn ($p) => $p !== '');

        return implode(', ', $parts);
    }

    private function myStation(Request $request)
    {
        $user = $request->user();

        abort_if($user->role->value !== 'STATION_OPERATOR', 403, 'Apenas operadores de posto têm acesso a este painel.');

        $station = $user->operatedStation;

        abort_if(!$station, 404, 'Não existe posto atribuído à sua conta. Contacte o suporte do KERUGAS.');

        return $station;
    }
}
