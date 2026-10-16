<?php

namespace App\Support;

use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Organização das listas do painel por província → município (Outubro 2026).
 *
 * Devolve uma árvore com contagens — sempre a partir da MESMA consulta da
 * lista (com o âmbito de província do Admin já aplicado), por isso um Admin
 * provincial só vê grupos da sua província:
 *
 *   [{province_id, province, count, municipalities: [{municipality_id, municipality, count}]}]
 *
 * `null` = "Sem província"/"Sem município" (ex.: Super Admin geral). Os
 * filtros de lista aceitam `province_id=none`/`municipality_id=none` para
 * mostrar exatamente esses.
 */
final class LocationGroups
{
    public const NONE = 'none';

    /** Agrupa por colunas da própria tabela (users, stations, gas_orders). */
    public static function byColumns(Builder $query, string $provinceCol = 'province_id', ?string $municipalityCol = 'municipality_id'): array
    {
        $q = (clone $query)->setEagerLoads([])->reorder();
        $q->getQuery()->columns = null;

        $cols = array_filter([$provinceCol, $municipalityCol]);
        $rows = $q->select($cols)->selectRaw('COUNT(*) as aggregate')->groupBy($cols)->get()
            ->map(fn ($r) => [
                (int) ($r->getAttribute(self::attr($provinceCol)) ?? 0) ?: null,
                $municipalityCol ? ((int) ($r->getAttribute(self::attr($municipalityCol)) ?? 0) ?: null) : null,
                (int) $r->getAttribute('aggregate'),
            ]);

        return self::tree($rows->all(), $municipalityCol !== null);
    }

    /** Agrupa pela localização do POSTO (comentários, denúncias). */
    public static function byStation(Builder $query, string $stationCol = 'station_id'): array
    {
        $q = (clone $query)->setEagerLoads([])->reorder();
        $q->getQuery()->columns = null;

        $perStation = $q->select($stationCol)->selectRaw('COUNT(*) as aggregate')->groupBy($stationCol)
            ->pluck('aggregate', self::attr($stationCol));

        $stations = Station::withTrashed()->whereIn('id', $perStation->keys())->get(['id', 'province_id', 'municipality_id'])->keyBy('id');

        $rows = [];
        foreach ($perStation as $stationId => $count) {
            $s = $stations->get($stationId);
            $rows[] = [$s?->province_id, $s?->municipality_id, (int) $count];
        }

        return self::tree($rows, true);
    }

    /** Aplica `province_id`/`municipality_id` (incl. "none") a colunas da tabela. */
    public static function filterColumns(Builder $query, Request $request, string $provinceCol = 'province_id', string $municipalityCol = 'municipality_id'): void
    {
        self::apply($query, $request->query('province_id'), $provinceCol);
        self::apply($query, $request->query('municipality_id'), $municipalityCol);
    }

    /** O mesmo, pela localização do posto. */
    public static function filterStation(Builder $query, Request $request, string $relation = 'station'): void
    {
        $province = $request->query('province_id');
        $municipality = $request->query('municipality_id');
        if (($province === null || $province === '') && ($municipality === null || $municipality === '')) {
            return;
        }

        // withTrashed: as contagens (byStation) também incluem postos apagados.
        $query->whereHas($relation, function (Builder $s) use ($province, $municipality) {
            $s->withTrashed();
            self::apply($s, $province, 'province_id');
            self::apply($s, $municipality, 'municipality_id');
        });
    }

    private static function apply(Builder $query, mixed $value, string $column): void
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return; // inclui ?province_id[]=… (nunca um erro 500)
        }
        if ($value === self::NONE) {
            $query->whereNull($column);

            return;
        }
        if (ctype_digit((string) $value)) {
            $query->where($column, (int) $value);
        }
    }

    /** @param list<array{0:?int,1:?int,2:int}> $rows */
    private static function tree(array $rows, bool $withMunicipalities): array
    {
        $provinceIds = array_values(array_unique(array_filter(array_column($rows, 0))));
        $municipalityIds = array_values(array_unique(array_filter(array_column($rows, 1))));
        $provinces = Province::whereIn('id', $provinceIds)->pluck('name', 'id');
        $municipalities = Municipality::whereIn('id', $municipalityIds)->pluck('name', 'id');

        $tree = [];
        foreach ($rows as [$p, $m, $count]) {
            $key = $p ?? 0;
            $tree[$key] ??= [
                'province_id' => $p,
                'province' => $p !== null ? ($provinces[$p] ?? 'Província #' . $p) : 'Sem província',
                'count' => 0,
                'municipalities' => [],
            ];
            $tree[$key]['count'] += $count;

            if ($withMunicipalities) {
                $mKey = $m ?? 0;
                $tree[$key]['municipalities'][$mKey] ??= [
                    'municipality_id' => $m,
                    'municipality' => $m !== null ? ($municipalities[$m] ?? 'Município #' . $m) : 'Sem município',
                    'count' => 0,
                ];
                $tree[$key]['municipalities'][$mKey]['count'] += $count;
            }
        }

        $byName = function (array $a, array $b, string $idKey, string $nameKey): int {
            // "Sem …" vai sempre para o fim.
            if (($a[$idKey] === null) !== ($b[$idKey] === null)) {
                return $a[$idKey] === null ? 1 : -1;
            }

            return strcasecmp(Str::ascii($a[$nameKey]), Str::ascii($b[$nameKey]));
        };

        $tree = array_values($tree);
        foreach ($tree as &$province) {
            $province['municipalities'] = array_values($province['municipalities']);
            usort($province['municipalities'], fn ($a, $b) => $byName($a, $b, 'municipality_id', 'municipality'));
        }
        unset($province);
        usort($tree, fn ($a, $b) => $byName($a, $b, 'province_id', 'province'));

        return $tree;
    }

    private static function attr(string $column): string
    {
        $parts = explode('.', $column);

        return end($parts);
    }
}
