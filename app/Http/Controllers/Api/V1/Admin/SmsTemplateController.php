<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSmsTemplateRequest;
use App\Models\AuditLog;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SmsTemplate::orderBy('type')->orderBy('name')->get(),
            'meta' => ['allowed_variables' => SmsTemplateRenderer::ALLOWED_VARIABLES],
        ]);
    }

    public function store(StoreSmsTemplateRequest $request): JsonResponse
    {
        $template = SmsTemplate::create(array_merge($request->validated(), [
            'created_by' => $request->user()->id,
        ]));

        $this->audit($request, 'template_create', $template, null, $template->only(['name', 'code', 'type']));

        return response()->json(['success' => true, 'data' => $template], 201);
    }

    public function update(StoreSmsTemplateRequest $request, SmsTemplate $smsTemplate): JsonResponse
    {
        $old = $smsTemplate->only(['name', 'content', 'type', 'active']);

        $smsTemplate->update(array_merge($request->validated(), [
            'updated_by' => $request->user()->id,
        ]));

        $this->audit($request, 'template_update', $smsTemplate, $old, $smsTemplate->only(['name', 'content', 'type', 'active']));

        return response()->json(['success' => true, 'data' => $smsTemplate->fresh()]);
    }

    public function destroy(Request $request, SmsTemplate $smsTemplate): JsonResponse
    {
        // Nunca apagar um template já usado em envios — apenas desativar,
        // preservando o histórico e a rastreabilidade das mensagens enviadas.
        $smsTemplate->update(['active' => false, 'updated_by' => $request->user()->id]);

        $this->audit($request, 'template_update', $smsTemplate, ['active' => true], ['active' => false]);

        return response()->json(['success' => true, 'data' => $smsTemplate->fresh()]);
    }

    private function audit(Request $request, string $action, SmsTemplate $template, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => SmsTemplate::class,
            'entity_id' => $template->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
