<?php

namespace App\Services;

use App\Enums\GasOrderStatus;
use App\Models\GasOrder;
use App\Models\GasOrderEvent;
use App\Models\User;

/**
 * Ranking/estatísticas simples do condutor (pedido explícito do
 * cliente, Setembro 2026) — visível ao próprio condutor
 * (`DriverOrderController::stats`), ao Admin e ao Super Admin
 * (`DriverAdminController::stats`), sempre o MESMO cálculo, daí viver
 * num serviço único em vez de duplicado nos dois controllers.
 *
 * "Tempo médio de entrega" usa o evento `status_changed` que registou
 * a transição para ASSIGNED (ver `GasOrderService::recordEvent`) até
 * `completed_at` — nunca `created_at` do pedido, porque um pedido pode
 * ter ficado muito tempo em PENDING/CONTACTING antes de sequer ter um
 * condutor atribuído; o que interessa aqui é o desempenho do condutor,
 * não da Central de Operações.
 */
class DriverStatsService
{
    public function forDriver(User $driver): array
    {
        $deliveredOrders = GasOrder::query()
            ->where('assigned_to', $driver->id)
            ->where('status', GasOrderStatus::DELIVERED)
            ->get(['id', 'completed_at']);

        $totalDelivered = $deliveredOrders->count();

        $totalCancelled = GasOrder::query()
            ->where('assigned_to', $driver->id)
            ->where('status', GasOrderStatus::CANCELLED)
            ->count();

        $totalAssigned = $totalDelivered + $totalCancelled;
        $cancellationRate = $totalAssigned > 0
            ? round(($totalCancelled / $totalAssigned) * 100, 1)
            : 0.0;

        $averageDeliveryMinutes = $this->averageDeliveryMinutes($deliveredOrders);

        // Contagem e média numa só consulta (Velocidade, Outubro 2026).
        $ratings = $driver->driverRatings()->toBase()
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();
        $ratingsCount = (int) ($ratings->total ?? 0);
        $averageRating = $ratingsCount > 0
            ? round((float) $ratings->average, 2)
            : null;

        return [
            'total_delivered' => $totalDelivered,
            'total_cancelled' => $totalCancelled,
            'cancellation_rate' => $cancellationRate,
            'average_delivery_minutes' => $averageDeliveryMinutes,
            'average_rating' => $averageRating,
            'ratings_count' => $ratingsCount,
        ];
    }

    /**
     * Tempo médio de entrega em minutos, calculado em PHP (não em SQL,
     * para não depender de funções de data específicas do driver de
     * BD — este projeto já teve de suportar SQLite nos testes e
     * MySQL/Postgres em produção, ver notas equivalentes noutros
     * serviços de estatísticas).
     */
    private function averageDeliveryMinutes(iterable $deliveredOrders): ?float
    {
        $orderIds = collect($deliveredOrders)->pluck('id')->all();

        if (empty($orderIds)) {
            return null;
        }

        $assignedAtByOrder = GasOrderEvent::query()
            ->whereIn('gas_order_id', $orderIds)
            ->where('to_status', GasOrderStatus::ASSIGNED->value)
            ->orderBy('created_at')
            ->get(['gas_order_id', 'created_at'])
            ->unique('gas_order_id')
            ->keyBy('gas_order_id');

        $minutes = [];

        foreach ($deliveredOrders as $order) {
            $assignedEvent = $assignedAtByOrder->get($order->id);

            if (!$assignedEvent || !$order->completed_at) {
                continue;
            }

            $diff = $order->completed_at->diffInMinutes($assignedEvent->created_at);

            if ($diff >= 0) {
                $minutes[] = $diff;
            }
        }

        if (empty($minutes)) {
            return null;
        }

        return round(array_sum($minutes) / count($minutes), 1);
    }
}
