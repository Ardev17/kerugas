<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DriverRating;
use App\Models\GasOrder;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação do condutor (pedido explícito do cliente, Setembro 2026).
 * Rota PÚBLICA (sem `auth:sanctum`) porque a maioria dos clientes que
 * pedem gás não tem conta — o mesmo caso já documentado em
 * `GasOrderController::showByReference` (que exige login e posse do
 * pedido) não serve aqui, então a "prova de posse" usada é referência
 * do pedido + telefone do cliente, o mesmo nível de verificação usado
 * nesta app noutros fluxos por telefone (Central de Operações).
 *
 * Só pode avaliar: um pedido `DELIVERED`, entregue por um Condutor
 * (não outro tipo de atribuição antiga), e ainda sem avaliação — tudo
 * verificado aqui, nunca confiado ao frontend.
 */
class DriverRatingController extends Controller
{
    public function __construct(private readonly PhoneNumberService $phoneNumberService)
    {
    }

    /**
     * Estado da avaliação para a página pública (antes de submeter) —
     * diz se já pode avaliar, se já avaliou, e quem foi o condutor.
     * Exige o mesmo par referência+telefone que o `store()`, para
     * nunca expor nome do condutor/estado do pedido por adivinhação
     * da referência sozinha.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['customer_phone' => ['required', 'string']]);

        $order = $this->findVerifiedOrder($reference, $data['customer_phone']);

        $existingRating = $order->rating;

        return response()->json([
            'success' => true,
            'data' => [
                'reference' => $order->order_reference,
                'driver_name' => $order->assignedUser?->name,
                'eligible' => $this->isEligible($order) && !$existingRating,
                'already_rated' => (bool) $existingRating,
                'rating' => $existingRating?->rating,
                'comment' => $existingRating?->comment,
                'ineligible_reason' => $this->ineligibleReason($order),
            ],
        ]);
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'customer_phone' => ['required', 'string'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->findVerifiedOrder($reference, $data['customer_phone']);

        abort_unless($this->isEligible($order), 422, $this->ineligibleReason($order) ?? 'Este pedido não pode ser avaliado.');
        abort_if($order->rating, 422, 'Este pedido já foi avaliado.');

        $rating = DriverRating::create([
            'gas_order_id' => $order->id,
            'driver_id' => $order->assigned_to,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => ['rating' => $rating->rating, 'comment' => $rating->comment]], 201);
    }

    /**
     * Localiza o pedido pela referência e confirma que o telefone
     * indicado corresponde ao do pedido (normalizado dos dois lados —
     * `customer_phone` foi guardado tal como o cliente o escreveu no
     * formulário, nunca normalizado na criação do pedido).
     */
    private function findVerifiedOrder(string $reference, string $phone): GasOrder
    {
        $order = GasOrder::where('order_reference', $reference)->with('assignedUser')->firstOrFail();

        $normalizedInput = $this->phoneNumberService->normalize($phone);
        $normalizedStored = $this->phoneNumberService->normalize($order->customer_phone);

        abort_if(
            $normalizedInput === null || $normalizedStored === null || $normalizedInput !== $normalizedStored,
            403,
            'Telefone não corresponde a este pedido.'
        );

        return $order;
    }

    private function isEligible(GasOrder $order): bool
    {
        return $order->status === GasOrderStatus::DELIVERED
            && $order->assignedUser?->role === UserRole::DRIVER;
    }

    private function ineligibleReason(GasOrder $order): ?string
    {
        if ($order->status !== GasOrderStatus::DELIVERED) {
            return 'Este pedido ainda não foi entregue.';
        }

        if ($order->assignedUser?->role !== UserRole::DRIVER) {
            return 'Este pedido não foi entregue por um condutor.';
        }

        return null;
    }
}
