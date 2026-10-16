<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationSmsActionRequest;
use App\Http\Requests\Admin\StoreOperationRequest;
use App\Enums\SmsDispatchStatus;
use App\Exceptions\SmsDailyLimitExceededException;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\SmsTemplate;
use App\Services\Sms\OperationService;
use App\Services\Sms\SmsDispatchService;
use App\Services\Sms\SmsSegmentCounter;
use App\Services\Sms\SmsTextNormalizer;
use App\Services\Sms\SmsTemplateRenderer;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Módulo "Responder Cliente" da Central de Operações KeruGás.
 *
 * Fluxo exclusivamente manual: o admin lê o SMS no telefone físico da
 * Central e inicia aqui o atendimento — nada neste controller é
 * acionado a partir de um SMS recebido pelo sistema.
 */
class OperationController extends Controller
{
    public function __construct(
        private readonly OperationService $operationService,
        private readonly SmsTemplateRenderer $renderer,
        private readonly SmsSegmentCounter $segmentCounter,
        private readonly SmsDispatchService $dispatchService,
        private readonly SmsTextNormalizer $smsText,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function store(StoreOperationRequest $request): JsonResponse
    {
        $operation = $this->operationService->startDraft(
            $request->user(),
            $request->string('phone')->toString(),
            $request->integer('province_id') ?: null,
            $request->integer('municipality_id') ?: null,
            $request->input('original_message'),
        );

        return response()->json(['success' => true, 'data' => $operation], 201);
    }

    public function stations(Request $request, Operation $operation): JsonResponse
    {
        $this->assertScope($request, $operation);
        if (!$operation->municipality_id) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Selecione um município para consultar os postos.',
            ]);
        }

        $municipality = Municipality::findOrFail($operation->municipality_id);
        $stations = $this->operationService->stationsForMunicipality($municipality);

        return response()->json(['success' => true, 'data' => $stations]);
    }

    /**
     * Situação do cliente perante o limite de consultas grátis (Outubro
     * 2026): quantas já gastou este mês, se tem subscrição e, se a Central
     * o bloqueia, qual o template a enviar em vez da consulta.
     */
    public function subscription(Request $request, Operation $operation): JsonResponse
    {
        $this->assertScope($request, $operation);
        $status = $this->subscriptions->smsStatus($operation->phone, $operation->id);

        $suggested = $status['suggested_template_code']
            ? SmsTemplate::where('code', $status['suggested_template_code'])->where('active', true)->first()
            : null;

        return response()->json([
            'success' => true,
            'data' => $status + ['suggested_template_id' => $suggested?->id],
        ]);
    }

    public function preview(OperationSmsActionRequest $request, Operation $operation): JsonResponse
    {
        $this->assertScope($request, $operation);
        $template = SmsTemplate::where('active', true)->findOrFail($request->integer('sms_template_id'));

        $message = $this->renderMessage($operation, $template);
        $count = $this->segmentCounter->count($message);

        return response()->json([
            'success' => true,
            'data' => [
                'recipient' => $operation->phone,
                'province' => $operation->province?->name,
                'municipality' => $operation->municipality?->name,
                'template' => ['id' => $template->id, 'name' => $template->name, 'type' => $template->type->value],
                'message' => $message,
                'characters' => $count['length'],
                'encoding' => $count['encoding'],
                'segments' => $count['segments'],
            ],
        ]);
    }

    public function send(OperationSmsActionRequest $request, Operation $operation): JsonResponse
    {
        $this->assertScope($request, $operation);
        $template = SmsTemplate::where('active', true)->findOrFail($request->integer('sms_template_id'));

        // Subscrição (Outubro 2026): com a obrigatoriedade ligada, quem já
        // gastou as consultas grátis do mês e não tem subscrição ativa não
        // recebe resposta de consulta — só os templates de subscrição. A
        // verificação é aqui, no servidor, nunca só no ecrã.
        if ($template->type->isConsult()) {
            $subscription = $this->subscriptions->smsStatus($operation->phone, $operation->id);

            if ($subscription['blocked']) {
                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'sms_subscription_blocked',
                    'entity_type' => Operation::class,
                    'entity_id' => $operation->id,
                    'old_values' => null,
                    'new_values' => ['recipient' => $operation->phone, 'template_id' => $template->id, 'used' => $subscription['used'], 'limit' => $subscription['limit']],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => false,
                    'code' => 'SUBSCRIPTION_REQUIRED',
                    'message' => "Este cliente já usou as {$subscription['limit']} consultas grátis deste mês e não tem subscrição ativa. Envie-lhe o template de subscrição em vez da consulta.",
                    'data' => $subscription,
                ], 402);
            }
        }

        // A mensagem é sempre gerada de novo no servidor a partir do
        // template — nunca confiamos num texto final vindo do cliente.
        $message = $this->renderMessage($operation, $template);

        $this->operationService->attachTemplate($operation, $template);

        // Limite diário por Admin (Setembro 2026, pedido explícito do
        // cliente) — bloqueia o envio com uma mensagem clara em vez de
        // deixar o SMS sair. Regista-se em AuditLog mesmo bloqueado: o
        // Super Admin deve conseguir ver não só quem enviou o quê, mas
        // também quem tentou enviar e foi travado pelo limite.
        try {
            $dispatch = $this->dispatchService->dispatch(
                $request->user(),
                $operation->phone,
                $message,
                $template,
                $operation,
            );
        } catch (SmsDailyLimitExceededException $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'sms_daily_limit_blocked',
                'entity_type' => Operation::class,
                'entity_id' => $operation->id,
                'old_values' => null,
                'new_values' => ['recipient' => $operation->phone, 'template_id' => $template->id, 'limit' => $e->limit, 'sent_today' => $e->sentToday],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Atingiu o seu limite diário de {$e->limit} SMS ({$e->sentToday} já enviados hoje). Tente novamente amanhã ou contacte um Super Admin para rever o seu limite.",
            ], 429);
        }

        $wasSent = $dispatch->status === SmsDispatchStatus::SENT;

        if ($wasSent) {
            $this->operationService->markSent($operation);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $wasSent ? 'sms_send' : 'sms_failed',
            'entity_type' => Operation::class,
            'entity_id' => $operation->id,
            'old_values' => null,
            'new_values' => ['recipient' => $dispatch->recipient, 'template_id' => $template->id, 'status' => $dispatch->status->value],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => $wasSent,
            'data' => $dispatch,
        ], $wasSent ? 201 : 422);
    }

    public function cancel(Request $request, Operation $operation): JsonResponse
    {
        $this->assertScope($request, $operation);
        $this->operationService->cancel($operation);

        return response()->json(['success' => true, 'data' => $operation->fresh()]);
    }

    private function renderMessage(Operation $operation, SmsTemplate $template): string
    {
        $context = [];

        if ($operation->municipality_id) {
            $municipality = Municipality::find($operation->municipality_id);
            $stations = $municipality
                ? $this->operationService->stationsForMunicipality($municipality)->filter(fn (array $s) => $s['gas_available'])->values()
                : collect();

            $context = $this->operationService->buildTemplateContext($operation, $stations);
        } else {
            $now = now();
            $context = [
                'telefone' => $operation->phone,
                'provincia' => '',
                'municipio' => '',
                'quantidade_postos' => '0',
                'postos' => '',
                'data' => $now->format('d/m/Y'),
                'hora' => $now->format('H:i'),
            ];
        }

        // Custo (Setembro 2026): GSM-7 antes de pré-visualizar/enviar —
        // os templates usam "KERUGÁS"/"gás", e um só "á" punha o SMS todo
        // em UCS-2 (70 carateres), cobrado como 2. Ver SmsTextNormalizer.
        $context += $this->subscriptions->templateContext($operation->phone);

        return $this->smsText->toGsm7($this->renderer->render($template->content, $context));
    }

    /**
     * Admin e Super Admin Provincial (Outubro 2026) só agem sobre
     * atendimentos da sua província — o route model binding resolve
     * `{operation}` pelo ID, por isso a verificação tem de estar aqui.
     */
    private function assertScope(Request $request, Operation $operation): void
    {
        $user = $request->user();

        // O próprio autor do atendimento continua sempre a poder agir sobre ele.
        if ((int) $operation->admin_id === (int) $user->id) {
            return;
        }

        abort_unless($user->canAccessProvince($operation->province_id), 403, 'Este atendimento é de outra província.');
    }
}
