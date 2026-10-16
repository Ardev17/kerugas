<?php

namespace App\Services;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Events\GasOrderCreated;
use App\Models\GasOrder;
use App\Models\GasOrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquestra o ciclo de vida do pedido de gás (Flow B: "Pedir gás"),
 * do Master Build Prompt, secções 170-241.
 *
 * Princípio fundamental: o formulário deve ser simples — o serviço não
 * assume preço, prazo ou disponibilidade automática; apenas regista o
 * pedido e aciona o fluxo de contacto humano.
 */
class GasOrderService
{
    public function create(array $data): GasOrder
    {
        return DB::transaction(function () use ($data) {
            // REGRESSÃO CORRIGIDA (de novo): defaults explícitos antes
            // de $data, para location_source/priority nunca ficarem
            // `null` se algum dia forem criados pedidos sem passar por
            // StoreGasOrderRequest (o default da coluna na migration
            // ajuda, mas não cobre todos os caminhos de escrita).
            $order = GasOrder::create(array_merge([
                'location_source' => 'MANUAL',
                'priority' => 'NORMAL',
            ], $data, [
                'order_reference' => $this->generateReference(),
                'status' => GasOrderStatus::PENDING,
            ]));

            $this->recordEvent($order, 'created', null, GasOrderStatus::PENDING->value);

            // Mesma correção de resiliência do StationStatusService: o
            // pedido tem de ficar guardado mesmo que o Reverb esteja offline.
            try {
                event(new GasOrderCreated($order));
            } catch (Throwable $e) {
                Log::channel('single')->warning('[GasOrderService] Falha ao transmitir evento em tempo real (não crítico)', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $order;
        });
    }

    public function markContacted(GasOrder $order, ?User $actor = null): GasOrder
    {
        return $this->transition($order, GasOrderStatus::CONTACTING, $actor, [
            'contacted_at' => now(),
        ]);
    }

    /**
     * Faturação por entrega (pedido explícito do cliente, Setembro
     * 2026): o preço só existe a partir daqui — antes de confirmar,
     * o Admin ainda está a negociá-lo por telefone com o cliente, e o
     * sistema nunca o calcula sozinho (varia com a distância, mas isso
     * é critério humano de negociação, não uma fórmula automática).
     * `$deliveryPrice` é obrigatório nesta chamada — a validação de
     * "obrigatório" em si vive em `AdminOrderController::confirm()`
     * (via `Request::validate`), não aqui, mas o Service também não
     * aceita `null` para nunca ficar um pedido confirmado sem preço
     * por um caminho de código que esqueça de validar.
     */
    /**
     * `$cylinderTypesConfirmed` (pedido explícito do cliente, Setembro
     * 2026): confirmado no MESMO passo que o preço, nunca depois — o
     * Admin já está a olhar para o pedido e a falar com o cliente
     * neste momento, então confirma os dois dados de uma vez. Separado
     * por vírgula quando há mais de um tipo/garrafa; a validação de
     * "obrigatório" vive em `AdminOrderController::confirm()`, tal
     * como já acontecia para o preço.
     */
    public function confirm(GasOrder $order, float $deliveryPrice, string $cylinderTypesConfirmed, ?User $actor = null): GasOrder
    {
        $order->update([
            'delivery_price' => $deliveryPrice,
            'price_set_by' => $actor?->id,
            'price_set_at' => now(),
            'cylinder_types_confirmed' => $cylinderTypesConfirmed,
        ]);

        return $this->transition($order, GasOrderStatus::CONFIRMED, $actor, [
            'confirmed_at' => now(),
        ], "Preço acertado: " . number_format($deliveryPrice, 2, ',', '.') . " Kz. Garrafas: {$cylinderTypesConfirmed}.");
    }

    /**
     * Reaproveita o mecanismo de atribuição já existente
     * (`assigned_to`/ASSIGNED) tanto para o caso original (qualquer
     * atribuição) como para o novo fluxo de Condutor. DISPONIBILIDADE
     * AUTOMÁTICA (pedido explícito do cliente): quando o atribuído é um
     * Condutor, ele fica automaticamente indisponível — nunca lhe é
     * enviado um segundo pedido em simultâneo por engano. Volta a
     * `true` automaticamente em `complete()`/`cancel()`; o condutor
     * também pode alterná-lo manualmente a qualquer momento
     * (DriverOrderController::toggleAvailability).
     */
    public function assign(GasOrder $order, User $assignee, ?User $actor = null): GasOrder
    {
        $order->update(['assigned_to' => $assignee->id]);

        if ($assignee->role === UserRole::DRIVER) {
            $assignee->update(['driver_available' => false]);
        }

        // AUDITORIA DE SEGURANÇA (Setembro 2026): sem isto, o evento só
        // registava "mudou para ASSIGNED" — se o pedido fosse reatribuído
        // mais de uma vez, o histórico não dizia A QUEM cada atribuição
        // foi feita (só o `assigned_to` atual do pedido sobrevivia).
        return $this->transition($order, GasOrderStatus::ASSIGNED, $actor, [], "Atribuído a {$assignee->name} (ID {$assignee->id}).");
    }

    public function startDelivery(GasOrder $order, ?User $actor = null): GasOrder
    {
        return $this->transition($order, GasOrderStatus::OUT_FOR_DELIVERY, $actor);
    }

    public function complete(GasOrder $order, ?User $actor = null): GasOrder
    {
        $this->releaseDriverIfAny($order);

        return $this->transition($order, GasOrderStatus::DELIVERED, $actor, [
            'completed_at' => now(),
        ]);
    }

    public function cancel(GasOrder $order, ?User $actor = null, ?string $reason = null): GasOrder
    {
        $this->releaseDriverIfAny($order);

        return $this->transition($order, GasOrderStatus::CANCELLED, $actor, [
            'cancelled_at' => now(),
        ], $reason);
    }

    /**
     * Fluxo do condutor (pedido explícito do cliente): direto para
     * Feita/Cancelado, sem passo manual intermédio de "em entrega" —
     * `startDelivery()`/OUT_FOR_DELIVERY continua a existir só para o
     * fluxo administrativo antigo, nunca é usado pelo Condutor.
     */
    private function releaseDriverIfAny(GasOrder $order): void
    {
        $assignee = $order->assignedUser;

        if ($assignee && $assignee->role === UserRole::DRIVER) {
            $assignee->update(['driver_available' => true]);
        }
    }

    private function transition(
        GasOrder $order,
        GasOrderStatus $newStatus,
        ?User $actor,
        array $extraAttributes = [],
        ?string $notes = null,
    ): GasOrder {
        return DB::transaction(function () use ($order, $newStatus, $actor, $extraAttributes, $notes) {
            $fromStatus = $order->status?->value;

            $order->update(array_merge($extraAttributes, ['status' => $newStatus]));

            $this->recordEvent($order, 'status_changed', $fromStatus, $newStatus->value, $actor, $notes);

            return $order->fresh();
        });
    }

    private function recordEvent(
        GasOrder $order,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?User $actor = null,
        ?string $notes = null,
    ): void {
        GasOrderEvent::create([
            'gas_order_id' => $order->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $actor?->id,
            'notes' => $notes,
        ]);
    }

    /**
     * Gera referência pública única no formato KG-YYYYMMDD-NNNNNN.
     * Nunca usar apenas o ID interno como referência pública (secção 180).
     */
    private function generateReference(): string
    {
        do {
            $reference = 'KG-' . now()->format('Ymd') . '-' . Str::padLeft((string) random_int(0, 999999), 6, '0');
        } while (GasOrder::where('order_reference', $reference)->exists());

        return $reference;
    }
}
