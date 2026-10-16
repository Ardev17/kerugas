<?php

namespace App\Services\Stock;

use App\Enums\GasOrderStatus;
use App\Models\BottlePickup;
use App\Models\GasOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Stock de garrafas do condutor (Outubro 2026).
 *
 *   em mão      = garrafas carregadas nos postos − garrafas entregues
 *                 (só pedidos com `stock_driver_id`, gravado ao concluir);
 *   reservadas  = garrafas de pedidos atribuídos ao condutor e ainda por
 *                 entregar (ASSIGNED / OUT_FOR_DELIVERY);
 *   disponíveis = em mão − reservadas.
 *
 * As garrafas que sobram no fim do dia ficam com o condutor (o stock passa
 * de um dia para o outro). Sem garrafas suficientes não se começa nem se
 * confirma uma entrega.
 */
class DriverStockService
{
    private const OPEN = [GasOrderStatus::ASSIGNED, GasOrderStatus::OUT_FOR_DELIVERY];

    public function forDriver(int $driverId, ?int $excludeOrderId = null): array
    {
        return $this->forDrivers(collect([$driverId]), $excludeOrderId)[$driverId];
    }

    /** @return array<int, array{picked:int, delivered:int, on_hand:int, reserved:int, available:int}> */
    public function forDrivers(Collection $driverIds, ?int $excludeOrderId = null): array
    {
        $ids = $driverIds->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $picked = BottlePickup::whereIn('driver_id', $ids)
            ->selectRaw('driver_id, SUM(quantity) as total')->groupBy('driver_id')->pluck('total', 'driver_id');

        $delivered = GasOrder::whereIn('stock_driver_id', $ids)->where('status', GasOrderStatus::DELIVERED)
            ->selectRaw('stock_driver_id, SUM(quantity) as total')->groupBy('stock_driver_id')->pluck('total', 'stock_driver_id');

        $reserved = GasOrder::whereIn('assigned_to', $ids)->whereIn('status', self::OPEN)
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->selectRaw('assigned_to, SUM(quantity) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');

        $out = [];
        foreach ($ids as $id) {
            $p = (int) ($picked[$id] ?? 0);
            $d = (int) ($delivered[$id] ?? 0);
            $r = (int) ($reserved[$id] ?? 0);
            $out[$id] = [
                'picked' => $p,
                'delivered' => $d,
                'on_hand' => $p - $d,
                'reserved' => $r,
                'available' => $p - $d - $r,
            ];
        }

        return $out;
    }

    /** Para começar uma entrega: as garrafas livres (sem contar este pedido) chegam? */
    public function assertCanStart(User $driver, GasOrder $order): void
    {
        $stock = $this->forDriver($driver->id, $order->id);
        $need = max(1, (int) $order->quantity);

        if ($stock['available'] < $need) {
            throw ValidationException::withMessages([
                'stock' => "O condutor {$driver->name} só tem {$stock['available']} garrafa(s) disponível(eis) e este pedido precisa de {$need}. Registe primeiro um carregamento num posto.",
            ]);
        }
    }

    /** Para confirmar a entrega: as garrafas em mão chegam? */
    public function assertCanComplete(User $driver, GasOrder $order): void
    {
        $stock = $this->forDriver($driver->id);
        $need = max(1, (int) $order->quantity);

        if ($stock['on_hand'] < $need) {
            throw ValidationException::withMessages([
                'stock' => "Só há {$stock['on_hand']} garrafa(s) no stock de {$driver->name} e este pedido tem {$need}. Registe primeiro um carregamento num posto.",
            ]);
        }
    }
}
