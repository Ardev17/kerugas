<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\Province;
use App\Services\Metrics\MunicipalityMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Métricas por município (Novembro 2026),SÓ o Super Admin geral e o
 * Provincial. A rota já exige role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN, por
 * isso o Admin comum nunca chega aqui.
 *
 * O Provincial vê apenas a sua província; o geral vê tudo e pode filtrar
 * por província. Períodos: hoje, 7 dias, 30 dias, ou um intervalo à escolha.
 */
class MunicipalityMetricsController extends Controller
{
    public function __construct(private readonly MunicipalityMetricsService $metrics)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->resolvePeriod($request);

        // Âmbito de províncias.
        if ($user->isProvinceSuperAdmin()) {
            $provinceIds = [(int) ($user->province_id ?? 0)];
        } elseif ($request->filled('province_id')) {
            $provinceIds = [(int) $request->integer('province_id')];
        } else {
            $provinceIds = null; // geral: todas
        }

        // Município pedido tem de pertencer ao âmbito.
        $municipalityId = null;
        if ($request->filled('municipality_id')) {
            $municipality = Municipality::find($request->integer('municipality_id'));
            if (!$municipality || ($provinceIds !== null && !in_array((int) $municipality->province_id, $provinceIds, true))) {
                throw ValidationException::withMessages(['municipality_id' => 'Esse município está fora do seu âmbito.']);
            }
            $municipalityId = $municipality->id;
            $provinceIds = [(int) $municipality->province_id];
        }

        $data = $this->metrics->build($from, $to, $provinceIds, $municipalityId);

        return response()->json([
            'success' => true,
            'data' => $data + [
                'scope' => [
                    'type' => $user->isProvinceSuperAdmin() ? 'province' : ($provinceIds === null ? 'national' : 'province'),
                    'province' => $user->isProvinceSuperAdmin()
                        ? $user->province?->name
                        : ($provinceIds !== null ? Province::find($provinceIds[0])?->name : null),
                    'can_pick_province' => $user->isGlobalSuperAdmin(),
                ],
            ],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvePeriod(Request $request): array
    {
        $period = $request->string('period')->toString() ?: 'today';
        $now = now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()],
            'custom' => $this->customPeriod($request, $now),
            default => throw ValidationException::withMessages(['period' => 'Período inválido. Use today, 7d, 30d ou custom.']),
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function customPeriod(Request $request, Carbon $now): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'A data final não pode ser anterior à inicial.']);
        }

        // Não medir o futuro: o período nunca passa de agora.
        if ($to->gt($now)) {
            $to = $now->copy();
        }

        // Teto de segurança: no máximo ~1 ano por consulta.
        if ($from->lt($now->copy()->subDays(366))) {
            throw ValidationException::withMessages(['from' => 'O intervalo não pode ser maior do que um ano.']);
        }

        return [$from, $to];
    }
}
