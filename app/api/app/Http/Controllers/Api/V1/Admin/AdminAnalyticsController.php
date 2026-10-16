<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Station;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agregações para os gráficos do painel admin (secção 42). Consultas
 * diretas sobre analytics_events/gas_orders — se o volume crescer muito,
 * mover para tabelas agregadas pré-calculadas (job noturno).
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só vê
 * analytics da SUA província. Isto é direto para "orders_by_day" e os
 * dois gráficos de postos (`gas_orders`/`stations` têm `province_id`
 * próprio), mas `analytics_events` NÃO tem `province_id`/
 * `municipality_id` — só o evento `station_search` guarda um nome de
 * município em texto livre no `payload` (ver `analyticsService.ts` no
 * frontend). Por isso:
 *   - "searches_by_day" e "top_municipalities_searched" são filtrados
 *     por essa lista de nomes de município (município → província).
 *   - "page_views_by_day" fica SEMPRE nacional, mesmo para um ADMIN
 *     escopado — o evento `page_view` não carrega localização nenhuma
 *     no payload (ex: `{ page: 'home' }'), não há nada a filtrar. Fingir
 *     um filtro aqui seria pior do que não ter filtro nenhum.
 * A resposta inclui `scope` para o frontend poder avisar sobre isto,
 * em vez do gráfico "desaparecer" dados sem explicação.
 */
class AdminAnalyticsController extends Controller
{
    use ScopesToAdminProvince;

    public function index(Request $request): JsonResponse
    {
        $days = 14;
        $since = now()->subDays($days - 1)->startOfDay();

        $scoped = $this->isProvinceScopedAdmin($request);
        $provinceId = $scoped ? $this->adminProvinceId($request) : null;
        $municipalityNames = $scoped
            ? Municipality::where('province_id', $provinceId)->pluck('name')->all()
            : null;

        $stationsQuery = Station::query();
        if ($scoped) {
            $stationsQuery->where('province_id', $provinceId);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'scope' => $scoped
                    ? ['type' => 'province', 'province' => $request->user()->province?->name]
                    : ['type' => 'national'],
                'searches_by_day' => $this->countByDay('station_search', $since, $days, $municipalityNames),
                'page_views_by_day' => $this->countByDay('page_view', $since, $days, null),
                'orders_by_day' => $this->ordersByDay($since, $days, $provinceId),
                'top_municipalities_searched' => $this->topMunicipalitiesSearched($municipalityNames),
                'stations_by_gas_availability' => [
                    ['name' => 'Com gás', 'value' => (clone $stationsQuery)->where('current_gas_available', true)->count()],
                    ['name' => 'Sem gás', 'value' => (clone $stationsQuery)->where('current_gas_available', false)->count()],
                ],
                'stations_by_queue_level' => [
                    ['name' => 'Fila baixa', 'value' => (clone $stationsQuery)->where('current_queue_level', 'LOW')->count()],
                    ['name' => 'Fila média', 'value' => (clone $stationsQuery)->where('current_queue_level', 'MEDIUM')->count()],
                    ['name' => 'Fila alta', 'value' => (clone $stationsQuery)->where('current_queue_level', 'HIGH')->count()],
                ],
            ],
        ]);
    }

    /**
     * @param string[]|null $municipalityNames Quando não-nulo, restringe
     *        aos eventos cujo `payload->municipality` esteja nesta lista.
     *        `null` = sem filtro nenhum (uso nacional, ou evento sem
     *        sinal geográfico como `page_view`).
     */
    private function countByDay(string $event, $since, int $days, ?array $municipalityNames): array
    {
        $query = AnalyticsEvent::query()
            ->where('event', $event)
            ->where('created_at', '>=', $since);

        if ($municipalityNames !== null) {
            $query->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.municipality'))"), $municipalityNames);
        }

        $rows = $query->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->fillMissingDays($rows, $since, $days);
    }

    private function ordersByDay($since, int $days, ?int $provinceId): array
    {
        $query = GasOrder::query()->where('created_at', '>=', $since);

        if ($provinceId !== null) {
            $query->where('province_id', $provinceId);
        }

        $rows = $query->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->fillMissingDays($rows, $since, $days);
    }

    /**
     * Garante que dias sem eventos aparecem como 0 no gráfico, em vez
     * de simplesmente desaparecerem (um gráfico de linha com "buracos"
     * confunde mais do que ajuda).
     */
    private function fillMissingDays($rows, $since, int $days): array
    {
        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->format('Y-m-d');
            $result[] = ['date' => $date, 'total' => (int) ($rows[$date] ?? 0)];
        }

        return $result;
    }

    private function topMunicipalitiesSearched(?array $municipalityNames): array
    {
        $query = AnalyticsEvent::query()
            ->where('event', 'station_search')
            ->whereNotNull('payload->municipality');

        if ($municipalityNames !== null) {
            $query->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.municipality'))"), $municipalityNames);
        }

        return $query
            ->select(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.municipality')) as municipality"), DB::raw('COUNT(*) as total'))
            ->groupBy('municipality')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['name' => $row->municipality, 'total' => (int) $row->total])
            ->toArray();
    }
}
