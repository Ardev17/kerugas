<?php

namespace App\Services\Subscriptions;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Definições das subscrições, guardadas em `app_settings` (JSON) e
 * editáveis pelo Super Admin. Tudo com valor por omissão seguro:
 * a obrigatoriedade vem DESLIGADA,nada muda para ninguém até o Super
 * Admin a ligar.
 */
class SubscriptionSettings
{
    private const CACHE_KEY = 'kerugas.subscription_settings';

    private const DEFAULTS = [
        // Interruptor geral: só quando ligado é que a Central bloqueia
        // consultas depois das grátis.
        'sms_enforcement' => false,
        'free_consults_per_month' => 3,
        // Dados de pagamento (1.ª fase: transferência bancária + comprovativo).
        'bank_name' => null,
        'account_holder' => null,
        'iban' => null,
        // Canal onde o cliente envia o comprovativo (WhatsApp, etc.). Sem
        // valor, usa a linha da central (config kerugas.contact.central_phone).
        'contact' => null,
        // Preços em Kz: ficam nulos até estarem definidos,a variável
        // {{valor}} dos templates só tem conteúdo quando o preço existe.
        'price_sms_kz' => null,
        'price_analytics_kz' => null,
        // Pedidos do município para postos (Novembro 2026): preço por SEMANA,
        // alterável só pelo Super Admin geral.
        'price_station_orders_week_kz' => 1000,
        // Presença do posto na aplicação,a terceira via B2B, pensada para
        // quando a KeruGás já tiver postos próprios e aderência que chegue
        // (ver nota em StationListingService). DESLIGADA: enquanto for false,
        // nada no comportamento da aplicação muda, e nem sequer se faz uma
        // consulta a mais. Só o Super Admin geral a liga.
        'listing_enforcement' => false,
        'price_station_listing_week_kz' => null,
        // Dias de tolerância depois de a subscrição de presença expirar. Só
        // ao fim deles é que o posto sai mesmo do mapa e das pesquisas.
        'listing_grace_days' => 4,
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $stored = AppSetting::query()
                ->whereIn('key', array_keys(self::DEFAULTS))
                ->pluck('value', 'key')
                ->map(fn ($v) => $v === null ? null : json_decode($v, true))
                ->all();

            return array_merge(self::DEFAULTS, $stored);
        });
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values, ?int $userId = null): array
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            AppSetting::updateOrCreate(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_by' => $userId]
            );
        }

        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }

    public function enforcementEnabled(): bool
    {
        return (bool) $this->get('sms_enforcement');
    }

    /** Preço semanal da presença do posto na aplicação, em Kz. */
    public function stationListingWeekPrice(): int
    {
        return (int) ($this->get('price_station_listing_week_kz') ?? 0);
    }

    /** Dias de tolerância depois de a presença expirar, antes de o posto sair. */
    public function listingGraceDays(): int
    {
        return max(0, (int) ($this->get('listing_grace_days') ?? 4));
    }

    /** Presença paga dos postos: está a ser exigida? Por omissão, não. */
    public function listingEnforced(): bool
    {
        return (bool) $this->get('listing_enforcement');
    }

    public function freeConsults(): int
    {
        return max(0, (int) $this->get('free_consults_per_month'));
    }

    public function contact(): string
    {
        return (string) ($this->get('contact') ?: config('kerugas.contact.central_phone'));
    }

    public function stationOrdersWeekPrice(): int
    {
        return max(0, (int) $this->get('price_station_orders_week_kz'));
    }

    public function priceLabel(string $key): string
    {
        $price = $this->get($key);

        return $price ? number_format((int) $price, 0, ',', '.') . ' Kz' : '';
    }
}
