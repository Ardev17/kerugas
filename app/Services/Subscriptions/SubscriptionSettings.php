<?php

namespace App\Services\Subscriptions;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Definições das subscrições, guardadas em `app_settings` (JSON) e
 * editáveis pelo Super Admin. Tudo com valor por omissão seguro:
 * a obrigatoriedade vem DESLIGADA — nada muda para ninguém até o Super
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
        // Preços em Kz: ficam nulos até estarem definidos — a variável
        // {{valor}} dos templates só tem conteúdo quando o preço existe.
        'price_sms_kz' => null,
        'price_analytics_kz' => null,
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

    public function freeConsults(): int
    {
        return max(0, (int) $this->get('free_consults_per_month'));
    }

    public function contact(): string
    {
        return (string) ($this->get('contact') ?: config('kerugas.contact.central_phone'));
    }

    public function priceLabel(string $key): string
    {
        $price = $this->get($key);

        return $price ? number_format((int) $price, 0, ',', '.') . ' Kz' : '';
    }
}
