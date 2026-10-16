<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\GasOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Station;
use App\Support\Concerns\ScopesToAdminProvince;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mapa de calor da procura (Outubro 2026): de onde vêm os pedidos e a que
 * horas — para decidir onde ter condutores e de que postos carregar.
 *
 * - "Procura" = todos os pedidos FEITOS no período, incluindo os
 *   cancelados (também são pessoas a pedir gás).
 * - Pontos: só pedidos com localização (GPS/mapa), agrupados numa grelha
 *   de ~110 m (3 casas decimais) — nunca a morada exata de um cliente.
 * - Hora × dia da semana: hora de Angola (config kerugas.notifications.timezone).
 * - Bairros: o texto que o cliente escreveu, normalizado (maiúsculas,
 *   espaços), por município — cobre também os pedidos sem GPS.
 *
 * ÂMBITO: Admin e Super Admin Provincial só a sua província; o Super
 * Admin geral pode filtrar por província ou ver tudo.
 */
class DemandController extends Controller
{
    use ScopesToAdminProvince;

    private const DAYS = [7, 30, 90];
    private const WEEKDAYS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:' . implode(',', self::DAYS)],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $tz = config('kerugas.notifications.timezone', 'Africa/Luanda');
        $since = now($tz)->subDays($days - 1)->startOfDay()->setTimezone(config('app.timezone'));

        $provinceId = $this->isProvinceScopedAdmin($request)
            ? $this->adminProvinceId($request)
            : ($data['province_id'] ?? null);

        $municipalityId = $data['municipality_id'] ?? null;
        if ($municipalityId && $provinceId !== null
            && (int) Municipality::whereKey($municipalityId)->value('province_id') !== (int) $provinceId) {
            abort(403, 'Este município pertence a outra província.');
        }

        $orders = GasOrder::query()
            ->where('created_at', '>=', $since)
            ->when($provinceId !== null, fn ($q) => $q->where('province_id', $provinceId))
            ->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId))
            ->with('municipality:id,name')
            ->select(['id', 'created_at', 'status', 'latitude', 'longitude', 'neighborhood', 'municipality_id', 'quantity'])
            ->latest('created_at')->limit(50000)
            ->get();

        // Grelha de pontos (~110 m).
        $cells = [];
        foreach ($orders as $o) {
            if ($o->latitude === null || $o->longitude === null) {
                continue;
            }
            $lat = round((float) $o->latitude, 3);
            $lng = round((float) $o->longitude, 3);
            $key = "{$lat}|{$lng}";
            $cells[$key] ??= ['lat' => $lat, 'lng' => $lng, 'count' => 0, 'delivered' => 0];
            $cells[$key]['count']++;
            if ($o->status === GasOrderStatus::DELIVERED) {
                $cells[$key]['delivered']++;
            }
        }
        $points = collect($cells)->sortByDesc('count')->take(2000)->values()->all();

        // Dia da semana × hora (Angola).
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($orders as $o) {
            $local = $o->created_at->copy()->setTimezone($tz);
            $grid[$local->dayOfWeekIso - 1][$local->hour]++;
        }

        $byHour = array_map(fn ($h) => array_sum(array_column($grid, $h)), range(0, 23));
        $byWeekday = array_map('array_sum', $grid);
        $peakHour = array_sum($byHour) > 0 ? array_search(max($byHour), $byHour, true) : null;
        $peakDay = array_sum($byWeekday) > 0 ? array_search(max($byWeekday), $byWeekday, true) : null;

        // Bairros (texto do cliente, normalizado).
        $hoods = [];
        foreach ($orders as $o) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $o->neighborhood));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name) . '|' . $o->municipality_id;
            $hoods[$key] ??= ['neighborhood' => mb_convert_case($name, MB_CASE_TITLE), 'municipality' => $o->municipality?->name, 'orders' => 0, 'delivered' => 0, 'bottles' => 0];
            $hoods[$key]['orders']++;
            $hoods[$key]['bottles'] += (int) $o->quantity;
            if ($o->status === GasOrderStatus::DELIVERED) {
                $hoods[$key]['delivered']++;
            }
        }

        $byMunicipality = $orders->groupBy('municipality_id')->map(fn ($rows) => [
            'municipality' => $rows->first()->municipality?->name ?? '—',
            'orders' => $rows->count(),
            'delivered' => $rows->where('status', GasOrderStatus::DELIVERED)->count(),
        ])->sortByDesc('orders')->take(10)->values()->all();

        $withLocation = $orders->filter(fn ($o) => $o->latitude !== null && $o->longitude !== null)->count();

        $stations = Station::query()->where('status', 'ACTIVE')
            ->when($provinceId !== null, fn ($q) => $q->where('province_id', $provinceId))
            ->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId))
            ->limit(500)->get(['id', 'name', 'latitude', 'longitude'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'lat' => (float) $s->latitude, 'lng' => (float) $s->longitude])
            ->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'scope' => $provinceId === null ? 'all' : 'province',
                'days' => $days,
                'totals' => [
                    'orders' => $orders->count(),
                    'delivered' => $orders->where('status', GasOrderStatus::DELIVERED)->count(),
                    'cancelled' => $orders->where('status', GasOrderStatus::CANCELLED)->count(),
                    'bottles' => (int) $orders->sum('quantity'),
                    'with_location' => $withLocation,
                    'location_rate' => $orders->count() > 0 ? round($withLocation / $orders->count() * 100, 1) : null,
                ],
                'peak' => [
                    'hour' => $peakHour,
                    'weekday' => $peakDay !== null ? self::WEEKDAYS[$peakDay] : null,
                ],
                'points' => $points,
                'weekday_hour' => ['weekdays' => self::WEEKDAYS, 'grid' => $grid],
                'by_hour' => $byHour,
                'neighborhoods' => collect($hoods)->sortByDesc('orders')->take(15)->values()->all(),
                'municipalities' => $byMunicipality,
                'stations' => $stations,
            ],
        ]);
    }
}
