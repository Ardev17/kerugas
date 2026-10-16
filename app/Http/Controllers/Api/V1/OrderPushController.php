<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GasOrder;
use App\Models\OrderPushSubscription;
use App\Services\PhoneNumberService;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avisos push de um pedido para quem NÃO tem conta (Outubro 2026).
 *
 * Mesma prova de posse do acompanhamento e da avaliação (referência +
 * telefone do pedido) e o mesmo `throttle:ratings` contra tentativas de
 * adivinhar o telefone. Um pedido terminado já não aceita subscrições —
 * não haveria nada para avisar.
 */
class OrderPushController extends Controller
{
    public function __construct(private readonly PhoneNumberService $phones)
    {
    }

    public function store(Request $request, string $reference, WebPushSender $sender): JsonResponse
    {
        $data = $request->validate([
            'customer_phone' => ['required', 'string', 'max:30'],
            'endpoint' => ['required', 'url:https', 'max:1000', function (string $attribute, mixed $value, \Closure $fail) {
                if (!is_string($value) || !WebPushSubscriptionController::isKnownPushService($value)) {
                    $fail('Serviço de notificações do navegador não reconhecido.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        $order = $this->ownedOrder($reference, $data['customer_phone']);

        abort_unless($sender->isEnabled(), 503, 'As notificações não estão ativas neste servidor.');
        abort_if(in_array($order->status->value, ['DELIVERED', 'CANCELLED', 'FAILED'], true), 409, 'Este pedido já terminou.');

        OrderPushSubscription::upsert([[
            'gas_order_id' => $order->id,
            'endpoint' => $data['endpoint'],
            'endpoint_hash' => hash('sha256', $data['endpoint']),
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['gas_order_id', 'endpoint_hash'], ['public_key', 'auth_token', 'content_encoding', 'user_agent', 'updated_at']);

        return response()->json(['success' => true], 201);
    }

    public function destroy(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'customer_phone' => ['required', 'string', 'max:30'],
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        $order = $this->ownedOrder($reference, $data['customer_phone']);

        OrderPushSubscription::where('gas_order_id', $order->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['success' => true]);
    }

    private function ownedOrder(string $reference, string $phone): GasOrder
    {
        $order = GasOrder::where('order_reference', $reference)->firstOrFail();

        $input = $this->phones->normalize($phone);
        $stored = $this->phones->normalize($order->customer_phone);

        // 404 igual ao de "referência inexistente": não confirma que a referência existe.
        abort_if($input === null || $stored === null || $input !== $stored, 404);

        return $order;
    }
}
