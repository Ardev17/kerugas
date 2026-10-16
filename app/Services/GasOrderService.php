<?php

namespace App\Services;

use App\Enums\GasOrderStatus;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Events\GasOrderCreated;
use App\Events\GasOrderCreatedBroadcast;
use App\Jobs\SendOrderGuestPushJob;
use App\Models\AppNotification;
use App\Models\GasOrder;
use App\Models\GasOrderEvent;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use App\Services\Stock\DriverStockService;
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

            // Campanhas (Setembro 2026): um pedido até 48h depois de uma
            // notificação conta como resultado dela. Melhor esforço.
            try {
                app(CampaignService::class)->markConversion($order);
            } catch (Throwable $e) {
                Log::channel('single')->warning('[GasOrderService] Falha a registar conversão de campanha (não crítico)', ['error' => $e->getMessage()]);
            }

            // Mesma correção de resiliência do StationStatusService: o
            // pedido tem de ficar guardado mesmo que o Reverb esteja offline.
            // Avisos aos Admins (domínio) e tempo real (Reverb) separados: uma
            // falha do Reverb nunca pode impedir os avisos (Outubro 2026).
            try {
                event(new GasOrderCreated($order));
            } catch (Throwable $e) {
                Log::channel('single')->warning('[GasOrderService] Falha nos avisos do pedido novo (não crítico)', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                event(new GasOrderCreatedBroadcast($order));
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
        $order = $this->transition($order, GasOrderStatus::CONTACTING, $actor, [
            'contacted_at' => now(),
        ]);

        $this->notifyCustomer($order, $actor, 'Estamos a contactá-lo', "A nossa equipa está a tratar do pedido {$order->order_reference} e vai contactá-lo.");

        return $order;
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
    /**
     * @param array{bottle_price?: float|int, commission?: float|int, quantity?: int} $billing
     *        Divisão da faturação (Outubro 2026, KeruGas intermediária):
     *        valor real das garrafas (pertence ao posto) + comissão (da
     *        KeruGas). O total que o cliente vê/paga (`delivery_price`) é
     *        SEMPRE a soma — quando `$billing` traz os dois valores, o
     *        `$deliveryPrice` recebido é ignorado e recalculado. Sem
     *        `$billing` (chamadas antigas) mantém-se o comportamento antigo.
     */
    public function confirm(GasOrder $order, float $deliveryPrice, string $cylinderTypesConfirmed, ?User $actor = null, array $billing = []): GasOrder
    {
        $fields = [];

        if (isset($billing['bottle_price'], $billing['commission'])) {
            $deliveryPrice = round((float) $billing['bottle_price'] + (float) $billing['commission'], 2);
            $fields['bottle_price'] = round((float) $billing['bottle_price'], 2);
            $fields['commission'] = round((float) $billing['commission'], 2);
        }

        // O número de garrafas nunca diminui.
        if (isset($billing['quantity']) && (int) $billing['quantity'] > (int) $order->quantity) {
            $fields['quantity'] = (int) $billing['quantity'];
        }

        $order->update($fields + [
            'delivery_price' => $deliveryPrice,
            'price_set_by' => $actor?->id,
            'price_set_at' => now(),
            'cylinder_types_confirmed' => $cylinderTypesConfirmed,
        ]);

        $order = $this->transition($order, GasOrderStatus::CONFIRMED, $actor, [
            'confirmed_at' => now(),
        ], "Preço acertado: " . number_format($deliveryPrice, 2, ',', '.') . " Kz. Garrafas: {$cylinderTypesConfirmed}.");

        $this->notifyCustomer(
            $order,
            $actor,
            'Pedido confirmado',
            "O seu pedido {$order->order_reference} foi confirmado. Entrega: " . number_format($deliveryPrice, 2, ',', '.') . ' Kz.'
        );

        return $order;
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
        $order = $this->transition($order, GasOrderStatus::ASSIGNED, $actor, [], "Atribuído a {$assignee->name} (ID {$assignee->id}).");

        // App Android (Setembro 2026): antes, o condutor não recebia
        // NENHUM aviso quando lhe atribuíam um pedido — só o descobria se
        // abrisse o painel. Agora recebe notificação no app (e push).
        if ($assignee->role === UserRole::DRIVER) {
            $place = $order->neighborhood ?: $order->address ?: $order->reference_point;
            $this->notifyUser(
                $assignee->id,
                'Novo pedido atribuído',
                "Pedido {$order->order_reference}" . ($place ? " · {$place}" : '') . '. Abra o painel para ver a rota.',
                ['gas_order_id' => $order->id, 'reference' => $order->order_reference]
            );

            $this->notifyCustomer($order, $actor, 'Condutor a caminho', "Um condutor foi atribuído ao seu pedido {$order->order_reference}.");
        }

        return $order;
    }

    public function startDelivery(GasOrder $order, ?User $actor = null): GasOrder
    {
        // Stock (Outubro 2026): um condutor só sai para entregar com garrafas.
        $assignee = $order->assignedUser;
        if ($assignee && $assignee->role === UserRole::DRIVER) {
            app(DriverStockService::class)->assertCanStart($assignee, $order);
        }

        $order = $this->transition($order, GasOrderStatus::OUT_FOR_DELIVERY, $actor);

        $this->notifyCustomer($order, $actor, 'O seu gás está a caminho', "O pedido {$order->order_reference} saiu para entrega.");

        return $order;
    }

    public function complete(GasOrder $order, ?User $actor = null): GasOrder
    {
        // Stock (Outubro 2026): as garrafas saem do stock do condutor. Sem
        // garrafas em mão, a entrega não pode ser confirmada. O lock no
        // condutor serializa duas confirmações em simultâneo.
        $assignee = $order->assignedUser;

        if ($assignee && $assignee->role === UserRole::DRIVER) {
            DB::transaction(function () use ($assignee, $order) {
                User::whereKey($assignee->id)->lockForUpdate()->first();
                app(DriverStockService::class)->assertCanComplete($assignee, $order);
                $order->update(['stock_driver_id' => $assignee->id]);
            });
        }

        $this->releaseDriverIfAny($order);

        $order = $this->transition($order, GasOrderStatus::DELIVERED, $actor, [
            'completed_at' => now(),
        ]);

        $this->notifyCustomer($order, $actor, 'Pedido entregue', "O pedido {$order->order_reference} foi entregue. Obrigado por usar o KeruGás!", final: true);

        return $order;
    }

    public function cancel(GasOrder $order, ?User $actor = null, ?string $reason = null): GasOrder
    {
        // Lido ANTES de libertar o condutor/transitar: depois do cancelamento
        // continua a ser o condutor que precisa de saber que a entrega acabou.
        $assignee = $order->assignedUser;

        $this->releaseDriverIfAny($order);

        $order = $this->transition($order, GasOrderStatus::CANCELLED, $actor, [
            'cancelled_at' => now(),
        ], $reason);

        $this->notifyCustomer($order, $actor, 'Pedido cancelado', "O pedido {$order->order_reference} foi cancelado.", final: true);

        // Condutor: se foi o Admin (ou o cliente) a cancelar, o condutor não
        // pode continuar a caminho de uma entrega que já não existe.
        if ($assignee && $assignee->role === UserRole::DRIVER && (!$actor || (int) $actor->id !== (int) $assignee->id)) {
            $this->notifyUser(
                $assignee->id,
                'Pedido cancelado',
                "O pedido {$order->order_reference} foi cancelado. Não é necessário fazer a entrega.",
                ['gas_order_id' => $order->id, 'reference' => $order->order_reference]
            );
        }

        // Equipa: quando quem cancela é o CLIENTE ou o CONDUTOR — qualquer papel que
        // não seja Admin/Super Admin (um Admin que cancela já sabe), os Admins da província têm de saber — sobretudo no
        // caso do condutor, para reatribuir o pedido.
        if ($actor && !in_array($actor->role, UserRole::managementRoles(), true)) {
            $who = $actor->role === UserRole::DRIVER ? "O condutor {$actor->name}" : 'O cliente';
            $this->notifyAdmins(
                $order,
                $actor->role === UserRole::DRIVER ? 'Condutor cancelou a entrega' : 'Cliente cancelou o pedido',
                "{$who} cancelou o pedido {$order->order_reference}."
            );
        }

        return $order;
    }

    /**
     * Avisa o cliente (quando o pedido foi feito com conta) de uma
     * mudança no SEU pedido. Nunca avisa a própria pessoa da ação que
     * ela mesma acabou de fazer (ex: cliente que cancela o próprio
     * pedido não recebe "o seu pedido foi cancelado").
     */
    private function notifyCustomer(GasOrder $order, ?User $actor, string $title, string $body, bool $final = false): void
    {
        // Cliente SEM conta (a maioria): aviso push para os navegadores que
        // subscreveram este pedido (referência + telefone). Nunca rebenta a
        // mudança de estado — já ficou gravada.
        if (!$order->user_id) {
            try {
                SendOrderGuestPushJob::dispatch($order->id, $title, $body, $final);
            } catch (Throwable $e) {
                Log::channel('single')->warning('[GasOrderService] Falha a enfileirar push de cliente sem conta (não crítico)', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        if ($actor && (int) $actor->id === (int) $order->user_id) {
            return;
        }

        $this->notifyUser($order->user_id, $title, $body, [
            'gas_order_id' => $order->id,
            'reference' => $order->order_reference,
        ]);
    }

    /** Avisa os Admins da província do pedido e os Super Admins. */
    private function notifyAdmins(GasOrder $order, string $title, string $body): void
    {
        try {
            foreach (app(OrderAudience::class)->admins($order) as $admin) {
                $this->notifyUser($admin->id, $title, $body, [
                    'gas_order_id' => $order->id,
                    'reference' => $order->order_reference,
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('single')->warning('[GasOrderService] Falha a avisar a equipa (não crítico)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * "Melhor esforço", como NotifyAdminsOfNewOrderListener: uma falha a
     * notificar nunca pode impedir uma mudança de estado do pedido, que
     * já ficou gravada. A push sai automaticamente desta notificação
     * (AppNotificationObserver).
     *
     * @param  array<string, mixed>  $data
     */
    private function notifyUser(int $userId, string $title, string $body, array $data): void
    {
        try {
            AppNotification::create([
                'user_id' => $userId,
                'category' => NotificationCategory::ORDER,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::channel('single')->warning('[GasOrderService] Falha ao criar notificação (não crítico)', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
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
