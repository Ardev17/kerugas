<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsProcessingService;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ponto de entrada do provedor de SMS. Nunca confia cegamente no
 * webhook — valida assinatura antes de processar (secção 27).
 */
class SmsWebhookController extends Controller
{
    public function __construct(
        private readonly SmsProviderInterface $smsProvider,
        private readonly SmsProcessingService $smsProcessingService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $headers = $request->headers->all();
        $rawBody = $request->getContent();

        if (!$this->smsProvider->verifyWebhook($headers, $rawBody)) {
            return response()->json(['success' => false, 'message' => 'Assinatura inválida.'], 401);
        }

        $normalized = $this->smsProvider->normalizeIncomingMessage($request->all());

        $smsMessage = $this->smsProcessingService->process($normalized);

        return response()->json([
            'success' => true,
            'data' => ['id' => $smsMessage->id, 'status' => $smsMessage->status->value],
        ]);
    }
}
