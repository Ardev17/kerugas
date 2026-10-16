<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastJob;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\CampaignDelivery;
use App\Models\NotificationCampaign;
use App\Services\Campaigns\AutomaticCampaignRunner;
use App\Services\Campaigns\BroadcastAudience;
use App\Services\Campaigns\CampaignCatalog;
use App\Services\Campaigns\CampaignService;
use App\Services\Sms\SmsSegmentCounter;
use App\Services\Sms\SmsTextNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Painel "Notificações" do Super Admin (Setembro 2026):
 * - campanhas automáticas: ver resultados, ligar/desligar, SMS, textos,
 *   enviar um teste para si próprio, correr já;
 * - envios manuais: escolher público, pré-visualizar alcance e custo de
 *   SMS, enviar já ou agendar, histórico.
 *
 * Rotas protegidas por `permission:notifications.manage` — Super Admin
 * geral e Super Admin Provincial (Outubro 2026). O Provincial só faz
 * ENVIOS aos utilizadores da SUA província (a província é imposta pelo
 * servidor) e só vê os envios dela; as campanhas automáticas são
 * configuração geral da plataforma — só o Super Admin geral.
 */
class NotificationCenterController extends Controller
{
    /** SMS com links são recusados pela KambaSMS. */
    private const LINK_PATTERN = '/(https?:|www\.|\.com\b|\.ao\b|\.net\b|\.org\b)/i';

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly SmsTextNormalizer $smsText,
        private readonly SmsSegmentCounter $segments,
    ) {
    }

    public function campaigns(Request $request): JsonResponse
    {
        $this->assertGlobal($request);
        $since = now()->subDays(30);

        $stats = CampaignDelivery::query()
            ->where('sent_at', '>=', $since)
            ->whereNull('broadcast_id')
            ->select('campaign_key', 'variant')
            ->selectRaw('COUNT(*) as sent')
            ->selectRaw('SUM(CASE WHEN converted_order_id IS NOT NULL THEN 1 ELSE 0 END) as converted')
            ->selectRaw("SUM(CASE WHEN sms_status = 'SENT' THEN 1 ELSE 0 END) as sms_sent")
            ->groupBy('campaign_key', 'variant')
            ->get()
            ->groupBy('campaign_key');

        $price = (float) config('kerugas.notifications.sms_price_kz', 100);

        $data = collect($this->campaigns->allDefinitions())->map(function (array $c) use ($stats, $price) {
            $rows = $stats->get($c['key'], collect());
            $sent = (int) $rows->sum('sent');
            $converted = (int) $rows->sum('converted');
            $smsSent = (int) $rows->sum('sms_sent');

            return [
                'key' => $c['key'],
                'name' => $c['name'],
                'description' => $c['description'],
                'trigger' => $c['trigger'],
                'schedule' => $c['schedule'],
                'audience' => $c['audience'],
                'category' => $c['category']->value,
                'marketing' => $c['marketing'],
                'enabled' => $c['enabled'],
                'sms_allowed' => $c['sms_allowed'],
                'sms_enabled' => $c['sms_enabled'],
                'variables' => $c['variables'],
                'templates' => $c['templates'],
                'can_run_now' => in_array($c['key'], AutomaticCampaignRunner::keys(), true),
                'stats' => [
                    'sent_30d' => $sent,
                    'converted_30d' => $converted,
                    'conversion_rate' => $sent > 0 ? round($converted / $sent * 100, 1) : null,
                    'sms_sent_30d' => $smsSent,
                    'sms_cost_30d_kz' => $smsSent * $price,
                    'variants' => $rows->map(fn ($r) => [
                        'variant' => (int) $r->variant,
                        'sent' => (int) $r->sent,
                        'converted' => (int) $r->converted,
                    ])->sortBy('variant')->values(),
                ],
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateCampaign(Request $request, string $key): JsonResponse
    {
        $this->assertGlobal($request);
        $definition = CampaignCatalog::find($key);
        abort_unless($definition, 404, 'Campanha desconhecida.');

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'sms_enabled' => ['sometimes', 'boolean'],
            'templates' => ['sometimes', 'nullable', 'array', 'min:1', 'max:5'],
            'templates.*.title' => ['required_with:templates', 'string', 'max:120'],
            'templates.*.body' => ['required_with:templates', 'string', 'max:500'],
            'templates.*.sms' => ['nullable', 'string', 'max:160', 'not_regex:' . self::LINK_PATTERN],
            'templates.*.occasion' => ['nullable', 'regex:/^(0[1-9]|[12]\d|3[01])-(0[1-9]|1[0-2])$/'],
        ], [
            'templates.*.sms.not_regex' => 'O SMS não pode ter links nem endereços (a operadora recusa o envio).',
            'templates.*.sms.max' => 'O SMS tem de caber em 160 carateres.',
            'templates.*.occasion.regex' => 'A data tem de estar no formato DD-MM (ex: 11-11).',
        ]);

        abort_if(($data['sms_enabled'] ?? false) && !$definition['sms_allowed'], 422, 'Esta campanha não pode ser enviada por SMS.');

        $campaign = NotificationCampaign::firstOrNew(['key' => $key]);
        $old = $campaign->exists ? $campaign->only(['enabled', 'sms_enabled', 'templates']) : null;

        $campaign->fill(array_merge(
            array_intersect_key($data, array_flip(['enabled', 'sms_enabled'])),
            array_key_exists('templates', $data) ? ['templates' => $data['templates'] ?: null] : [],
            ['updated_by' => $request->user()->id],
        ))->save();

        $this->audit($request, 'notification_campaign.updated', NotificationCampaign::class, $campaign->id, $old, $campaign->only(['key', 'enabled', 'sms_enabled', 'templates']));

        return response()->json(['success' => true, 'data' => $this->campaigns->definition($key)]);
    }

    /** Envia a campanha só para o próprio Super Admin, para ver como fica. */
    public function testCampaign(Request $request, string $key): JsonResponse
    {
        $this->assertGlobal($request);
        abort_unless(CampaignCatalog::find($key), 404, 'Campanha desconhecida.');

        $sample = [
            'dias' => 18, 'posto' => 'Posto Exemplo', 'municipio' => 'Lubango', 'anos' => '1 ano',
            'dica' => CampaignCatalog::find('safety_tip')['tips'][0], 'condutor' => 'António',
            'referencia' => 'KG-TESTE', 'horas' => 7, 'pedidos' => 12, 'entregues' => 10, 'pendentes' => 2,
            'station_id' => 1,
        ];

        $delivery = $this->campaigns->deliver($key, $request->user(), $sample, null, ['force' => true]);

        return response()->json([
            'success' => (bool) $delivery,
            'message' => $delivery
                ? 'Teste enviado para si — veja o sino e o seu telemóvel.'
                : 'Não foi possível enviar o teste.',
        ]);
    }

    /** Corre já uma campanha automática (ex: enviar a véspera de fim de semana mais cedo). */
    public function runCampaign(Request $request, string $key, AutomaticCampaignRunner $runner): JsonResponse
    {
        $this->assertGlobal($request);
        abort_unless(in_array($key, AutomaticCampaignRunner::keys(), true), 422, 'Esta campanha não pode ser corrida manualmente.');

        $sent = $runner->run($key);

        $this->audit($request, 'notification_campaign.run', NotificationCampaign::class, null, null, ['key' => $key, 'sent' => $sent]);

        return response()->json([
            'success' => true,
            'data' => ['sent' => $sent],
            'message' => $sent > 0
                ? "{$sent} notificação(ões) enviada(s)."
                : 'Ninguém a notificar agora (regras, preferências, horas de silêncio ou já enviado).',
        ]);
    }

    public function broadcasts(Request $request): JsonResponse
    {
        $scope = $request->user()->scopedProvinceId();
        $broadcasts = Broadcast::with(['creator:id,name', 'province:id,name', 'municipality:id,name'])
            ->when($scope !== null, fn ($q) => $q->where('province_id', $scope))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $broadcasts->getCollection()->map(fn (Broadcast $b) => [
                'id' => $b->id,
                'audience' => $b->audience,
                'audience_label' => BroadcastAudience::OPTIONS[$b->audience] ?? $b->audience,
                'province' => $b->province?->name,
                'municipality' => $b->municipality?->name,
                'kind' => $b->kind,
                'title' => $b->title,
                'body' => $b->body,
                'send_sms' => $b->send_sms,
                'status' => $b->status,
                'scheduled_at' => $b->scheduled_at?->toIso8601String(),
                'finished_at' => $b->finished_at?->toIso8601String(),
                'recipients_count' => $b->recipients_count,
                'sms_count' => $b->sms_count,
                'created_by' => $b->creator?->name,
                'created_at' => $b->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $broadcasts->currentPage(), 'last_page' => $broadcasts->lastPage()],
        ]);
    }

    /** Quantas pessoas recebem, quantas por SMS e quanto custa — antes de enviar. */
    public function previewBroadcast(Request $request): JsonResponse
    {
        $data = $this->validateBroadcast($request, preview: true);

        $query = BroadcastAudience::query($data['audience'], $data['province_id'] ?? null, $data['municipality_id'] ?? null);
        $recipients = (clone $query)->count();

        $smsRecipients = 0;
        $smsInfo = null;
        if (!empty($data['send_sms']) && !empty($data['sms_text'])) {
            $smsQuery = (clone $query)->whereNotNull('phone');
            if (($data['kind'] ?? 'announcement') === 'promo') {
                // Quem desligou SMS de avisos/promoções nas preferências fica de fora.
                $smsQuery->where(fn ($q) => $q->whereNull('notification_preferences')
                    ->orWhere('notification_preferences->sms', true)
                    ->orWhereNull('notification_preferences->sms'));
            }
            $smsRecipients = $smsQuery->count();
            $normalized = $this->smsText->toGsm7((string) $data['sms_text']);
            $smsInfo = ['text' => $normalized] + $this->segments->count($normalized);
        }

        $price = (float) config('kerugas.notifications.sms_price_kz', 100);
        $segments = (int) ($smsInfo['segments'] ?? 1);

        return response()->json([
            'success' => true,
            'data' => [
                'recipients' => $recipients,
                'sms_recipients' => $smsRecipients,
                'sms' => $smsInfo,
                'sms_cost_estimate_kz' => $smsRecipients * $segments * $price,
                'quiet_hours_now' => $this->campaigns->isQuietHours(),
            ],
        ]);
    }

    public function storeBroadcast(Request $request): JsonResponse
    {
        $data = $this->validateBroadcast($request);

        $scheduledAt = !empty($data['scheduled_at']) ? \Illuminate\Support\Carbon::parse($data['scheduled_at']) : null;
        $sendNow = !$scheduledAt || $scheduledAt->lte(now());

        $broadcast = DB::transaction(function () use ($request, $data, $scheduledAt, $sendNow) {
            $broadcast = Broadcast::create([
                'created_by' => $request->user()->id,
                'audience' => $data['audience'],
                'province_id' => $data['province_id'] ?? null,
                'municipality_id' => $data['municipality_id'] ?? null,
                'kind' => $data['kind'] ?? 'announcement',
                'title' => $data['title'],
                'body' => $data['body'],
                'path' => $data['path'] ?? null,
                'send_sms' => (bool) ($data['send_sms'] ?? false),
                'sms_text' => !empty($data['send_sms']) ? $this->smsText->toGsm7((string) $data['sms_text']) : null,
                'status' => $sendNow ? 'SENDING' : 'SCHEDULED',
                'scheduled_at' => $scheduledAt ?? now(),
                'started_at' => $sendNow ? now() : null,
            ]);

            $this->audit($request, 'broadcast.created', Broadcast::class, $broadcast->id, null, $broadcast->only([
                'audience', 'province_id', 'municipality_id', 'kind', 'title', 'send_sms', 'scheduled_at',
            ]));

            return $broadcast;
        });

        if ($sendNow) {
            SendBroadcastJob::dispatch($broadcast->id)->afterCommit();
        }

        return response()->json([
            'success' => true,
            'data' => ['id' => $broadcast->id, 'status' => $broadcast->status],
            'message' => $sendNow ? 'Envio iniciado.' : 'Envio agendado.',
        ], 201);
    }

    public function cancelBroadcast(Request $request, Broadcast $broadcast): JsonResponse
    {
        abort_unless($request->user()->canAccessProvince($broadcast->province_id), 403, 'Este envio é de outra província.');
        abort_unless($broadcast->status === 'SCHEDULED', 422, 'Só é possível cancelar envios ainda agendados.');

        $broadcast->update(['status' => 'CANCELLED']);
        $this->audit($request, 'broadcast.cancelled', Broadcast::class, $broadcast->id, null, null);

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBroadcast(Request $request, bool $preview = false): array
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(array_keys(BroadcastAudience::OPTIONS))],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'kind' => ['nullable', Rule::in(['announcement', 'promo'])],
            'title' => [$preview ? 'nullable' : 'required', 'string', 'max:120'],
            'body' => [$preview ? 'nullable' : 'required', 'string', 'max:500'],
            // Só ecrãs internos do KeruGás ("/pedir-gas", "/mapa"...).
            'path' => ['nullable', 'string', 'max:190', 'regex:#^/(?!/)[^\\\\\s\x00-\x1F\x7F]*$#'],
            'send_sms' => ['nullable', 'boolean'],
            'sms_text' => ['nullable', 'required_if:send_sms,true', 'string', 'max:160', 'not_regex:' . self::LINK_PATTERN],
            'scheduled_at' => ['nullable', 'date'],
        ], [
            'sms_text.not_regex' => 'O SMS não pode ter links nem endereços (a operadora recusa o envio).',
            'sms_text.required_if' => 'Escreva o texto do SMS.',
            'path.regex' => 'Indique um ecrã do KeruGás (ex: /pedir-gas).',
        ]);

        // Super Admin Provincial: o envio é SEMPRE para a sua província, e o
        // município (se escolhido) tem de ser dela.
        $scope = $request->user()->scopedProvinceId();
        if ($scope !== null) {
            $data['province_id'] = $scope;
            if (!empty($data['municipality_id'])
                && (int) \App\Models\Municipality::whereKey($data['municipality_id'])->value('province_id') !== $scope) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'municipality_id' => 'Escolha um município da sua província.',
                ]);
            }
        }

        return $data;
    }

    private function assertGlobal(Request $request): void
    {
        abort_unless($request->user()->isGlobalSuperAdmin(), 403, 'As campanhas automáticas são geridas pelo Super Admin geral.');
    }

    private function audit(Request $request, string $action, string $type, ?int $id, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
