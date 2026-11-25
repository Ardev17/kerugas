<?php

namespace App\Services\Subscriptions;

use App\Enums\OperationStatus;
use App\Enums\SmsTemplateType;
use App\Enums\SubscriptionType;
use App\Models\Operation;
use App\Models\Station;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Regras das subscrições mensais.
 *
 * CONSULTA POR SMS,como se conta: a consulta de gás é um atendimento
 * manual da Central (Operation). Conta como "consulta gasta" um
 * atendimento ENVIADO ao cliente com uma resposta de consulta
 * (disponibilidade, sem gás, info de posto) no mês civil em Angola.
 * Mensagens de subscrição, pedidos de localização e atendimentos
 * cancelados/rascunho não gastam consultas. Com o interruptor ligado, a
 * partir de N consultas (omissão: 3) o telemóvel só recebe resposta de
 * consulta se tiver subscrição ativa.
 */
class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionSettings $settings,
        private readonly PhoneNumberService $phones,
    ) {
    }

    public function settings(): SubscriptionSettings
    {
        return $this->settings;
    }

    /** Início do mês civil, na hora de Angola. */
    public function monthStart(?Carbon $now = null): Carbon
    {
        $tz = config('kerugas.notifications.timezone', 'Africa/Luanda');

        return ($now ?? now())->copy()->setTimezone($tz)->startOfMonth()->setTimezone(config('app.timezone'));
    }

    public function consultsUsed(string $phone, ?int $exceptOperationId = null): int
    {
        return Operation::query()
            ->where('phone', $phone)
            ->where('status', OperationStatus::SENT)
            ->where('updated_at', '>=', $this->monthStart())
            ->when($exceptOperationId, fn ($q) => $q->where('id', '!=', $exceptOperationId))
            ->whereHas('template', fn ($q) => $q->whereIn('type', SmsTemplateType::consultValues()))
            ->count();
    }

    public function activeSmsSubscription(string $phone): ?Subscription
    {
        return Subscription::query()
            ->covering()
            ->where('type', SubscriptionType::SMS_CONSULT)
            ->where('phone', $phone)
            ->orderByDesc('expires_at')
            ->first();
    }

    /**
     * A subscrição "do posto" por excelência: desde Novembro de 2026 é a da
     * presença, que traz o analytics incluído. Antes era a do analytics.
     */
    public function activeStationSubscription(Station $station): ?Subscription
    {
        return Subscription::query()
            ->covering()
            ->where('type', SubscriptionType::STATION_LISTING)
            ->where('station_id', $station->id)
            ->orderByDesc('expires_at')
            ->first();
    }

    /** Subscrição que deixa o posto receber os pedidos do seu município (Novembro 2026). */
    public function activeStationOrdersSubscription(Station $station): ?Subscription
    {
        return Subscription::query()
            ->covering()
            ->where('type', SubscriptionType::STATION_ORDERS)
            ->where('station_id', $station->id)
            ->orderByDesc('expires_at')
            ->first();
    }

    /**
     * Subscrição que mantém o posto visível na aplicação. Só conta quando a
     * definição `listing_enforcement` estiver ligada,ver StationListingService.
     */
    public function activeStationListingSubscription(Station $station): ?Subscription
    {
        return Subscription::query()
            ->covering()
            ->where('type', SubscriptionType::STATION_LISTING)
            ->where('station_id', $station->id)
            ->orderByDesc('expires_at')
            ->first();
    }

    /** Fim da cobertura contínua (inclui blocos já pagos a seguir), ou null. */
    public function stationOrdersPaidUntil(Station $station): ?Carbon
    {
        if (!$this->activeStationOrdersSubscription($station)) {
            return null;
        }

        $max = Subscription::query()
            ->where('type', SubscriptionType::STATION_ORDERS)
            ->where('station_id', $station->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->max('expires_at');

        return $max ? Carbon::parse($max) : null;
    }

    /**
     * Situação de um telemóvel perante o limite de consultas.
     *
     * @return array<string, mixed>
     */
    public function smsStatus(string $phone, ?int $exceptOperationId = null): array
    {
        $limit = $this->settings->freeConsults();
        $used = $this->consultsUsed($phone, $exceptOperationId);
        $active = $this->activeSmsSubscription($phone);
        $enforced = $this->settings->enforcementEnabled();
        $blocked = $enforced && $active === null && $used >= $limit;

        $hadBefore = Subscription::query()
            ->where('type', SubscriptionType::SMS_CONSULT)
            ->where('phone', $phone)
            ->exists();

        return [
            'enforced' => $enforced,
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'subscribed' => $active !== null,
            'valid_until' => $active?->expires_at->toIso8601String(),
            'blocked' => $blocked,
            'suggested_template_code' => $blocked ? ($hadBefore ? 'SUBSCRIPTION_EXPIRED' : 'SUBSCRIPTION_REQUIRED') : null,
        ];
    }

    /**
     * Variáveis dos templates de subscrição. Vazias quando não existem
     * (ex.: sem preço definido, `{{valor}}` fica em branco).
     *
     * @return array<string, string>
     */
    public function templateContext(?string $phone = null, ?Station $station = null): array
    {
        $valid = null;
        if ($phone) {
            $valid = $this->activeSmsSubscription($phone)?->expires_at;
        } elseif ($station) {
            $valid = $this->activeStationSubscription($station)?->expires_at;
        }

        return [
            'contacto' => $this->settings->contact(),
            'iban' => (string) ($this->settings->get('iban') ?? ''),
            'valor' => $this->settings->priceLabel($station ? 'price_analytics_kz' : 'price_sms_kz'),
            'validade' => $valid ? $valid->copy()->setTimezone(config('kerugas.notifications.timezone', 'Africa/Luanda'))->format('d/m/Y') : '',
            'limite' => (string) $this->settings->freeConsults(),
        ] + ($station ? ['posto' => $station->name] : []);
    }

    /**
     * Ativa (ou renova) uma subscrição. Se já há período a cobrir, o novo
     * começa quando o anterior acaba,o cliente nunca perde dias pagos.
     *
     * @param array{type: string, phone?: ?string, station_id?: ?int, months?: int, amount_kz?: ?int, payment_reference?: ?string, notes?: ?string} $data
     */
    public function activate(User $by, array $data): Subscription
    {
        $type = SubscriptionType::from($data['type']);
        $months = max(1, min(24, (int) ($data['months'] ?? 1)));

        $phone = null;
        $stationId = null;

        if ($type === SubscriptionType::SMS_CONSULT) {
            $phone = $this->phones->normalize((string) ($data['phone'] ?? ''));
            if ($phone === null) {
                throw new InvalidArgumentException('Telemóvel angolano inválido.');
            }
        } else {
            $stationId = (int) ($data['station_id'] ?? 0);
            if (!Station::whereKey($stationId)->exists()) {
                throw new InvalidArgumentException('Posto não encontrado.');
            }
        }

        $latest = Subscription::query()
            ->where('type', $type)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->when($phone, fn ($q) => $q->where('phone', $phone))
            ->when($stationId, fn ($q) => $q->where('station_id', $stationId))
            ->max('expires_at');

        $startsAt = $latest ? Carbon::parse($latest) : now();

        // Pedidos do município (Novembro 2026): blocos de SEMANAS; o valor por
        // omissão é o preço semanal × semanas.
        if ($type->isWeekly()) {
            $weeks = max(1, min(52, (int) ($data['weeks'] ?? 1)));
            $precoSemana = $type === SubscriptionType::STATION_LISTING
                ? $this->settings->stationListingWeekPrice()
                : $this->settings->stationOrdersWeekPrice();

            return Subscription::create([
                'type' => $type,
                'station_id' => $stationId,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'expires_at' => $startsAt->copy()->addWeeks($weeks),
                'months' => 0,
                'weeks' => $weeks,
                'amount_kz' => $data['amount_kz'] ?? ($weeks * $precoSemana),
                'payment_reference' => $data['payment_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'activated_by' => $by->id,
            ]);
        }

        return Subscription::create([
            'type' => $type,
            'phone' => $phone,
            'station_id' => $stationId,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addMonthsNoOverflow($months),
            'months' => $months,
            'amount_kz' => $data['amount_kz'] ?? null,
            'payment_reference' => $data['payment_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'activated_by' => $by->id,
        ]);
    }

    public function cancel(Subscription $subscription, User $by): Subscription
    {
        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $by->id,
        ]);

        return $subscription->fresh();
    }
}
