<?php

namespace App\Services\Metrics;

use App\Models\AnalyticsEvent;
use App\Models\Municipality;
use App\Models\Station;
use App\Models\StationStatusUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Métricas por município (Novembro 2026),só o Super Admin geral e o
 * Provincial as veem.
 *
 * Duas medidas, no período escolhido (hoje, 7 dias, 30 dias, ou um
 * intervalo à escolha):
 *
 *  - "Mais disponibilizam gás": tempo TOTAL em que cada posto/município
 *    esteve com gás disponível. Calcula-se a partir do histórico de
 *    estados (station_statuses): cada mudança de estado abre um
 *    intervalo que dura até à mudança seguinte (ou até ao fim do
 *    período). Somam-se os intervalos em que o gás estava disponível,
 *    cruzados com o período pedido. O estado em vigor no início do
 *    período é o último registo ANTES do início.
 *
 *  - "Mais requisições": consultas pela web e pela app,os eventos
 *    `station_search` (guardam o nome do município) e `station_view`
 *    (guardam o id do posto, que se converte no seu município).
 */
class MunicipalityMetricsService
{
    /**
     * @param int[]|null $provinceIds Províncias no âmbito; null = todas (Super Admin geral).
     * @param int|null   $municipalityId Restringe a um município.
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to, ?array $provinceIds, ?int $municipalityId): array
    {
        $stations = $this->stationsInScope($provinceIds, $municipalityId);
        $gasByStation = $this->gasSecondsByStation($stations->pluck('id')->all(), $from, $to);
        $requestsByMunicipality = $this->requestsByMunicipality($stations, $from, $to, $provinceIds, $municipalityId);

        $periodSeconds = max(1, $to->timestamp - $from->timestamp);

        // Ranking de postos por tempo com gás.
        $topStations = $stations
            ->map(function (Station $s) use ($gasByStation, $periodSeconds) {
                $seconds = $gasByStation[$s->id] ?? 0;

                return [
                    'station_id' => $s->id,
                    'station' => $s->name,
                    'code' => $s->code,
                    'municipality' => $s->municipality?->name,
                    'municipality_id' => $s->municipality_id,
                    'seconds_with_gas' => $seconds,
                    'label' => $this->durationLabel($seconds),
                    'percent' => round(min(100, $seconds / $periodSeconds * 100), 1),
                ];
            })
            ->sortByDesc('seconds_with_gas')
            ->values();

        // Agregado por município: soma do tempo com gás dos seus postos + requisições.
        $byMunicipalityGas = $topStations->groupBy('municipality_id')
            ->map(fn (Collection $rows) => $rows->sum('seconds_with_gas'));

        $municipalities = $stations
            ->groupBy('municipality_id')
            ->map(function (Collection $rows, $mid) use ($byMunicipalityGas, $requestsByMunicipality, $periodSeconds) {
                $first = $rows->first();
                $seconds = (int) ($byMunicipalityGas[$mid] ?? 0);
                $stationCount = $rows->count();

                return [
                    'municipality_id' => (int) $mid,
                    'municipality' => $first->municipality?->name,
                    'province' => $first->province?->name,
                    'stations_count' => $stationCount,
                    'seconds_with_gas' => $seconds,
                    'label_with_gas' => $this->durationLabel($seconds),
                    // Média por posto: compara municípios de forma justa (um
                    // município com mais postos soma mais tempo só por isso).
                    'avg_seconds_per_station' => $stationCount > 0 ? (int) round($seconds / $stationCount) : 0,
                    'avg_label_per_station' => $this->durationLabel($stationCount > 0 ? (int) round($seconds / $stationCount) : 0),
                    'requests' => (int) ($requestsByMunicipality[(int) $mid] ?? 0),
                ];
            })
            ->values();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'seconds' => $periodSeconds,
            ],
            'top_gas_availability' => $topStations,
            'top_requests' => $municipalities->sortByDesc('requests')->values(),
            'municipalities' => $municipalities->sortBy('municipality')->values(),
            'totals' => [
                'stations' => $stations->count(),
                'municipalities' => $municipalities->count(),
                'requests' => $municipalities->sum('requests'),
            ],
        ];
    }

    /** @return Collection<int, Station> */
    private function stationsInScope(?array $provinceIds, ?int $municipalityId): Collection
    {
        return Station::query()
            ->with(['municipality:id,name', 'province:id,name'])
            ->when($provinceIds !== null, fn ($q) => $q->whereIn('province_id', $provinceIds))
            ->when($municipalityId !== null, fn ($q) => $q->where('municipality_id', $municipalityId))
            ->get();
    }

    /**
     * Segundos com gás disponível por posto, no período.
     *
     * @param int[] $stationIds
     * @return array<int, int>
     */
    private function gasSecondsByStation(array $stationIds, Carbon $from, Carbon $to): array
    {
        if ($stationIds === []) {
            return [];
        }

        $result = [];

        // Estado em vigor no início do período: o último registo antes de `from`.
        $baseline = StationStatusUpdate::query()
            ->whereIn('station_id', $stationIds)
            ->where('created_at', '<', $from)
            ->orderBy('station_id')
            ->orderByDesc('created_at')
            ->get(['station_id', 'gas_available', 'created_at'])
            ->groupBy('station_id')
            ->map(fn ($rows) => (bool) $rows->first()->gas_available);

        // Mudanças de estado dentro do período.
        $changes = StationStatusUpdate::query()
            ->whereIn('station_id', $stationIds)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('station_id')
            ->orderBy('created_at')
            ->get(['station_id', 'gas_available', 'created_at'])
            ->groupBy('station_id');

        foreach ($stationIds as $id) {
            $available = (bool) ($baseline[$id] ?? false);
            $cursor = $from->copy();
            $seconds = 0;

            foreach ($changes->get($id, collect()) as $change) {
                $at = Carbon::parse($change->created_at);
                if ($at->gt($cursor)) {
                    if ($available) {
                        $seconds += $at->timestamp - $cursor->timestamp;
                    }
                    $cursor = $at;
                }
                $available = (bool) $change->gas_available;
            }

            if ($available && $to->gt($cursor)) {
                $seconds += $to->timestamp - $cursor->timestamp;
            }

            $result[$id] = $seconds;
        }

        return $result;
    }

    /**
     * Requisições (consultas) por município: `station_search` (nome do
     * município no payload) + `station_view` (id do posto → município).
     *
     * @param Collection<int, Station> $stations
     * @return array<int, int>
     */
    private function requestsByMunicipality(Collection $stations, Carbon $from, Carbon $to, ?array $provinceIds, ?int $municipalityId): array
    {
        // municipio (nome, minúsculas) → id, só no âmbito.
        $nameToId = Municipality::query()
            ->when($provinceIds !== null, fn ($q) => $q->whereIn('province_id', $provinceIds))
            ->when($municipalityId !== null, fn ($q) => $q->where('id', $municipalityId))
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($m) => [mb_strtolower($m->name) => $m->id]);

        $stationToMunicipality = $stations->pluck('municipality_id', 'id');

        $counts = [];

        // Agregação em PHP (portável SQLite/MySQL): só os dois tipos de
        // evento, já limitados ao período. O payload vem como array.
        AnalyticsEvent::query()
            ->whereIn('event', ['station_search', 'station_view'])
            ->whereBetween('created_at', [$from, $to])
            ->select(['event', 'payload'])
            ->chunk(2000, function ($rows) use (&$counts, $nameToId, $stationToMunicipality) {
                foreach ($rows as $row) {
                    $payload = $row->payload ?? [];

                    if ($row->event === 'station_search') {
                        $name = isset($payload['municipality']) ? mb_strtolower((string) $payload['municipality']) : null;
                        $mid = $name !== null ? ($nameToId[$name] ?? null) : null;
                    } else {
                        $sid = isset($payload['station_id']) ? (int) $payload['station_id'] : null;
                        $mid = $sid !== null ? ($stationToMunicipality[$sid] ?? null) : null;
                    }

                    if ($mid !== null) {
                        $counts[(int) $mid] = ($counts[(int) $mid] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }

    /** "18h 30m", "45m", "2d 4h". */
    private function durationLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = "{$minutes}m";
        }

        return $parts === [] ? '0m' : implode(' ', $parts);
    }
}
