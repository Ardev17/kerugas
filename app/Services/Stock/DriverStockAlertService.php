<?php

namespace App\Services\Stock;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\BottlePickup;
use App\Models\GasOrder;
use App\Models\User;

/**
 * Alerta de entregas "por fora da central" (Outubro 2026).
 *
 * O sistema não vê o que acontece fora dele, mas vê as garrafas: um condutor
 * que carrega garrafas nos postos e não as entrega com pedido da plataforma
 * deixa um rasto. Dois sinais, por condutor:
 *
 *  - PARADAS: garrafas em mão (carregadas − entregues − reservadas) acima do
 *    que foi carregado nos últimos 3 dias. Ou seja, garrafas que já deviam
 *    ter saído e continuam "no stock" sem pedido.
 *  - POUCAS ENTREGAS: nos últimos 30 dias, carregou 10 ou mais garrafas e
 *    entregou (com pedido) menos de 60 %.
 *
 * É um aviso para o admin perguntar e conferir o stock físico — não prova
 * nada por si só (um dia fraco também gera avisos).
 */
class DriverStockAlertService
{
    public const IDLE_DAYS = 3;

    public const IDLE_MIN_BOTTLES = 3;

    public const WINDOW_DAYS = 30;

    public const RATIO_MIN_PICKED = 10;

    public const RATIO_THRESHOLD = 0.6;

    public function __construct(private readonly DriverStockService $stock)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forAdmin(User $admin): array
    {
        $drivers = User::query()->where('role', UserRole::DRIVER)->where('status', 'ACTIVE')
            ->when($admin->scopedProvinceId() !== null, fn ($q) => $q->where('province_id', $admin->scopedProvinceId()))
            ->get(['id', 'name', 'province_id', 'municipality_id']);

        if ($drivers->isEmpty()) {
            return [];
        }

        $ids = $drivers->pluck('id');
        $stock = $this->stock->forDrivers($ids);

        $recentPicked = BottlePickup::whereIn('driver_id', $ids)->where('registered_at', '>=', now()->subDays(self::IDLE_DAYS))
            ->selectRaw('driver_id, SUM(quantity) as total')->groupBy('driver_id')->pluck('total', 'driver_id');
        $monthPicked = BottlePickup::whereIn('driver_id', $ids)->where('registered_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->selectRaw('driver_id, SUM(quantity) as total')->groupBy('driver_id')->pluck('total', 'driver_id');
        $monthDelivered = GasOrder::whereIn('stock_driver_id', $ids)->where('status', GasOrderStatus::DELIVERED)
            ->where('completed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->selectRaw('stock_driver_id, SUM(quantity) as total')->groupBy('stock_driver_id')->pluck('total', 'stock_driver_id');

        $alerts = [];
        foreach ($drivers as $driver) {
            $s = $stock[$driver->id];
            $idle = max(0, $s['on_hand'] - $s['reserved'] - (int) ($recentPicked[$driver->id] ?? 0));
            $picked = (int) ($monthPicked[$driver->id] ?? 0);
            $delivered = (int) ($monthDelivered[$driver->id] ?? 0);
            $ratio = $picked > 0 ? $delivered / $picked : null;

            $reasons = [];
            if ($idle >= self::IDLE_MIN_BOTTLES) {
                $reasons[] = ['type' => 'idle', 'bottles' => $idle];
            }
            if ($picked >= self::RATIO_MIN_PICKED && $ratio !== null && $ratio < self::RATIO_THRESHOLD) {
                $reasons[] = ['type' => 'low_delivery', 'picked' => $picked, 'delivered' => $delivered, 'percent' => (int) round($ratio * 100)];
            }

            if ($reasons !== []) {
                $alerts[] = [
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'on_hand' => $s['on_hand'],
                    'reasons' => $reasons,
                    // mais garrafas "sem rasto" primeiro
                    'score' => $idle + max(0, $picked - $delivered),
                ];
            }
        }

        usort($alerts, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $alerts;
    }
}
