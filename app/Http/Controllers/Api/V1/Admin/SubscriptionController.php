<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SmsDispatchStatus;
use App\Enums\SubscriptionType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Station;
use App\Models\Subscription;
use App\Services\PhoneNumberService;
use App\Services\Subscriptions\SubscriptionNotifier;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Subscriptions\SubscriptionSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Subscrições mensais (Outubro 2026) — Super Admin geral, e o Super Admin
 * Provincial só para POSTOS da sua província (nunca subscrições SMS de
 * clientes, que não têm província, nem preço/IBAN — esses são gerais). A ativação é manual: o cliente/operador paga
 * por transferência, envia o comprovativo por WhatsApp e o Super Admin
 * regista aqui o período. Nunca há ativação automática.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionNotifier $notifier,
        private readonly SubscriptionSmsService $sms,
        private readonly PhoneNumberService $phones,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with(['station.operator', 'activatedBy'])->latest('id');
        $this->scopeToProvince($request, $query);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        match ($request->query('state')) {
            'active' => $query->covering(),
            'expired' => $query->where('status', Subscription::STATUS_ACTIVE)->where('expires_at', '<=', now()),
            'cancelled' => $query->where('status', Subscription::STATUS_CANCELLED),
            'expiring' => $query->covering()->where('expires_at', '<=', now()->addDays(7)),
            default => null,
        };

        if ($search = trim((string) $request->query('search'))) {
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->whereHas('station', fn ($s) => $s->where('name', 'like', "%{$search}%"));
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        $page = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (Subscription $s) => $this->present($s))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'summary' => $this->summary($request->user()->scopedProvinceId()),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(SubscriptionType::class)],
            'phone' => ['required_if:type,SMS_CONSULT', 'nullable', 'string', 'max:20'],
            'station_id' => ['required_if:type,STATION_ANALYTICS', 'nullable', 'integer', 'exists:stations,id'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'amount_kz' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'enable_analytics' => ['nullable', 'boolean'],
            'send_confirmation_sms' => ['nullable', 'boolean'],
        ]);

        $scope = $request->user()->scopedProvinceId();
        if ($scope !== null) {
            abort_unless($data['type'] === SubscriptionType::STATION_ANALYTICS->value, 403, 'O Super Admin Provincial só ativa subscrições de postos.');
            abort_unless($request->user()->canAccessProvince(Station::find($data['station_id'])?->province_id), 403, 'Este posto pertence a outra província.');
        }

        if ($data['type'] === SubscriptionType::SMS_CONSULT->value && $this->phones->normalize((string) ($data['phone'] ?? '')) === null) {
            return response()->json([
                'message' => 'Telemóvel angolano inválido.',
                'errors' => ['phone' => ['Telemóvel angolano inválido.']],
            ], 422);
        }

        try {
            $subscription = $this->subscriptions->activate($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'subscription.activated', $subscription, null, $subscription->only(['type', 'phone', 'station_id', 'starts_at', 'expires_at', 'months', 'amount_kz']));

        $extra = [];

        if ($subscription->type === SubscriptionType::STATION_ANALYTICS) {
            $station = Station::with('operator')->findOrFail($subscription->station_id);
            $enabledNow = false;

            // Por omissão a subscrição NÃO liga o analytics: o interruptor
            // continua manual. Só liga se o Super Admin o pedir aqui.
            if (!empty($data['enable_analytics']) && !$station->analytics_enabled) {
                $station->update(['analytics_enabled' => true]);
                $enabledNow = true;
                $this->audit($request, 'station.analytics_toggled', $station, ['analytics_enabled' => false], ['analytics_enabled' => true]);
            }

            $extra['operator_notified'] = $this->notifier->subscriptionActivated($station, $subscription, $enabledNow);
            $extra['analytics_enabled'] = (bool) $station->analytics_enabled;
        } elseif (!empty($data['send_confirmation_sms'])) {
            $dispatch = $this->sms->send(
                $request->user(),
                'SUBSCRIPTION_ACTIVATED',
                $subscription->phone,
                $this->subscriptions->templateContext($subscription->phone),
            );
            $extra['confirmation_sms'] = $dispatch === null
                ? 'not_sent'
                : ($dispatch->status === SmsDispatchStatus::SENT ? 'sent' : 'failed');
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($subscription->load(['station.operator', 'activatedBy'])),
            'meta' => $extra,
        ], 201);
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        if ($request->user()->scopedProvinceId() !== null) {
            abort_unless(
                $subscription->type === SubscriptionType::STATION_ANALYTICS
                    && $request->user()->canAccessProvince($subscription->station?->province_id),
                403,
                'Esta subscrição está fora do âmbito da sua província.'
            );
        }

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Esta subscrição já está cancelada.'], 422);
        }

        $subscription = $this->subscriptions->cancel($subscription, $request->user());
        $this->audit($request, 'subscription.cancelled', $subscription, ['status' => Subscription::STATUS_ACTIVE], ['status' => Subscription::STATUS_CANCELLED]);

        return response()->json(['success' => true, 'data' => $this->present($subscription->load(['station.operator', 'activatedBy']))]);
    }

    /** Situação de um telemóvel: consultas gastas no mês + histórico. */
    public function lookup(Request $request): JsonResponse
    {
        abort_if($request->user()->scopedProvinceId() !== null, 403, 'As subscrições SMS de clientes são geridas pelo Super Admin geral.');

        $phone = $this->phones->normalize((string) $request->query('phone'));
        if ($phone === null) {
            return response()->json(['message' => 'Telemóvel angolano inválido.', 'errors' => ['phone' => ['Telemóvel angolano inválido.']]], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'phone' => $phone,
                'status' => $this->subscriptions->smsStatus($phone),
                'history' => Subscription::query()
                    ->with('activatedBy')
                    ->where('type', SubscriptionType::SMS_CONSULT)
                    ->where('phone', $phone)
                    ->latest('id')->limit(10)->get()
                    ->map(fn (Subscription $s) => $this->present($s))->values(),
            ],
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->subscriptions->settings()->all()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms_enforcement' => ['sometimes', 'boolean'],
            'free_consults_per_month' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_holder' => ['sometimes', 'nullable', 'string', 'max:160'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z]{2}\d{2}[A-Za-z0-9 ]{10,32}$/'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/(https?:|www\.)/i'],
            'price_sms_kz' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'price_analytics_kz' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ], [
            'iban.regex' => 'IBAN inválido. Exemplo: AO06 0040 0000 1234 5678 9012 3.',
            'contact.not_regex' => 'O contacto não pode ter links (a operadora de SMS recusa).',
        ]);

        // Cadeias vazias passam a "sem valor".
        foreach (['bank_name', 'account_holder', 'iban', 'contact'] as $k) {
            if (array_key_exists($k, $data) && is_string($data[$k]) && trim($data[$k]) === '') {
                $data[$k] = null;
            }
        }
        foreach (['price_sms_kz', 'price_analytics_kz'] as $k) {
            if (array_key_exists($k, $data) && (int) $data[$k] === 0) {
                $data[$k] = null; // 0 = "preço ainda não definido"
            }
        }

        $old = $this->subscriptions->settings()->all();
        $new = $this->subscriptions->settings()->update($data, $request->user()->id);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'subscription.settings_updated',
            'entity_type' => 'app_settings',
            'entity_id' => 0,
            'old_values' => array_intersect_key($old, $data),
            'new_values' => array_intersect_key($new, $data),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => $new]);
    }

    /** @return array<string, mixed> */
    private function present(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'type' => $s->type->value,
            'type_label' => $s->type->label(),
            'phone' => $s->phone,
            'station' => $s->station ? [
                'id' => $s->station->id,
                'name' => $s->station->name,
                'operator_name' => $s->station->operator?->name,
                'analytics_enabled' => (bool) $s->station->analytics_enabled,
            ] : null,
            'state' => $s->state,
            'starts_at' => $s->starts_at->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(),
            'months' => $s->months,
            'amount_kz' => $s->amount_kz,
            'payment_reference' => $s->payment_reference,
            'notes' => $s->notes,
            'activated_by' => $s->activatedBy?->name,
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
            'created_at' => $s->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, int> */
    private function summary(?int $provinceId = null): array
    {
        $base = function () use ($provinceId) {
            $q = Subscription::covering();
            if ($provinceId !== null) {
                $q->where('type', SubscriptionType::STATION_ANALYTICS)
                    ->whereHas('station', fn ($s) => $s->where('province_id', $provinceId));
            }

            return $q;
        };
        $active = fn (SubscriptionType $t) => $base()->where('type', $t)->count();

        return [
            'active_sms' => $provinceId === null ? $active(SubscriptionType::SMS_CONSULT) : 0,
            'active_stations' => $active(SubscriptionType::STATION_ANALYTICS),
            'expiring_7d' => $base()->where('expires_at', '<=', now()->addDays(7))->count(),
            'stations_analytics_off' => Station::where('analytics_enabled', false)->whereHas('operators')
                ->when($provinceId !== null, fn ($q) => $q->where('province_id', $provinceId))->count(),
        ];
    }

    /** Super Admin Provincial: só subscrições de postos da sua província. */
    private function scopeToProvince(Request $request, $query): void
    {
        $scope = $request->user()->scopedProvinceId();
        if ($scope === null) {
            return;
        }

        $query->where('type', SubscriptionType::STATION_ANALYTICS)
            ->whereHas('station', fn ($s) => $s->where('province_id', $scope));
    }

    private function audit(Request $request, string $action, object $entity, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
