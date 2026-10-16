<?php

namespace App\Services\Campaigns;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Jobs\SendCampaignSmsJob;
use App\Models\AppNotification;
use App\Models\Broadcast;
use App\Models\CampaignDelivery;
use App\Models\GasOrder;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor único de envio de campanhas (Setembro 2026) — automáticas,
 * disparadas por eventos, e envios manuais do Super Admin passam todos
 * por aqui, e por isso respeitam sempre as mesmas regras:
 *
 * 1. Campanha ligada (o Super Admin pode desligar qualquer uma).
 * 2. Pessoa ativa e com o papel certo (um condutor não recebe o
 *    lembrete de gás de cliente).
 * 3. Preferências da pessoa (lembretes / alertas / dicas / promoções / SMS).
 * 4. Horas de silêncio (21:00–08:00, hora de Angola): nada sai de noite.
 * 5. Promoções: no máximo 2 por semana por pessoa.
 * 6. Nunca repetir: cada envio tem uma chave única (dedupe_key).
 *
 * O envio cria uma notificação normal do app (AppNotification) — que já
 * chega ao sino do site/app, e por push ao telemóvel e ao navegador pelo
 * AppNotificationObserver. O SMS, quando se aplica, sai por uma fila à parte.
 */
class CampaignService
{
    public const MARKETING_WEEKLY_CAP = 2;

    /**
     * Definição efetiva: catálogo + afinações do Super Admin.
     *
     * @return array<string, mixed>|null
     */
    public function definition(string $key): ?array
    {
        $definition = CampaignCatalog::find($key);
        if (!$definition) {
            return null;
        }

        $override = NotificationCampaign::where('key', $key)->first();

        $definition['enabled'] = $override?->enabled ?? true;
        $definition['sms_enabled'] = $definition['sms_allowed'] && ($override?->sms_enabled ?? $definition['sms_default']);
        if (!empty($override?->templates)) {
            $definition['templates'] = $override->templates;
        }

        return $definition;
    }

    /** @return array<string, array<string, mixed>> */
    public function allDefinitions(): array
    {
        $overrides = NotificationCampaign::all()->keyBy('key');

        return collect(CampaignCatalog::all())->map(function (array $definition, string $key) use ($overrides) {
            $override = $overrides->get($key);
            $definition['enabled'] = $override?->enabled ?? true;
            $definition['sms_enabled'] = $definition['sms_allowed'] && ($override?->sms_enabled ?? $definition['sms_default']);
            if (!empty($override?->templates)) {
                $definition['templates'] = $override->templates;
            }

            return $definition;
        })->all();
    }

    /**
     * Envia uma campanha a uma pessoa, se todas as regras o permitirem.
     * Devolve null quando não envia (e isso é normal: regra a funcionar).
     *
     * @param  array<string, scalar|null>  $vars
     * @param  array{force?: bool, templates?: array<int, array<string, string>>, ignore_quiet_hours?: bool}  $options
     *   templates: variantes a usar (pode ser um subconjunto com os índices originais).
     *   force: envio de teste do Super Admin para si próprio — ignora
     *   campanha desligada, papel, preferências, horas de silêncio e limite.
     *   ignore_quiet_hours: resposta imediata a uma ação da própria pessoa
     *   (ex: boas-vindas logo após o registo) — pode sair a qualquer hora.
     */
    public function deliver(string $key, User $user, array $vars = [], ?string $dedupeKey = null, array $options = []): ?CampaignDelivery
    {
        $definition = $this->definition($key);
        $force = (bool) ($options['force'] ?? false);

        if (!$definition || (!$definition['enabled'] && !$force)) {
            return null;
        }

        if (!$force && !$this->eligible($user, $definition, !($options['ignore_quiet_hours'] ?? false))) {
            return null;
        }

        if ($dedupeKey && CampaignDelivery::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        // `templates` pode vir filtrado (ex: datas especiais só com as
        // variantes do dia) mantendo os índices originais — a variante
        // guardada é sempre o índice no catálogo, para as estatísticas
        // do painel baterem certo com o texto que foi enviado.
        $candidates = $options['templates'] ?? $definition['templates'];
        if ($candidates === []) {
            return null;
        }

        $indexes = array_keys($candidates);
        $position = $this->variantFor($user, $key, count($candidates));
        $template = $candidates[$indexes[$position]];
        $variant = is_int($indexes[$position]) ? $indexes[$position] : $position;
        $values = $this->baseVariables($user) + array_map(fn ($v) => (string) $v, $vars);

        $wantsSms = $definition['sms_enabled']
            && $user->phone
            && ($definition['preference'] === null || $user->wantsNotifications('sms'))
            && !empty($template['sms']);

        return $this->persist(
            key: $key,
            user: $user,
            category: $definition['category'],
            marketing: (bool) $definition['marketing'],
            title: $this->render((string) $template['title'], $values),
            body: $this->render((string) $template['body'], $values),
            path: $this->render((string) $definition['path'], $values),
            channel: (string) $definition['channel'],
            smsText: $wantsSms ? $this->render((string) $template['sms'], $values) : null,
            dedupeKey: $force ? null : $dedupeKey,
            variant: $variant,
        );
    }

    /**
     * Envio manual do Super Admin a uma pessoa. Anúncios chegam sempre;
     * promoções respeitam a preferência "promoções" da pessoa (mas não o
     * limite semanal — é uma decisão consciente do Super Admin).
     */
    public function deliverBroadcast(Broadcast $broadcast, User $user): ?CampaignDelivery
    {
        $isPromo = $broadcast->kind === 'promo';

        if ($isPromo && !$user->wantsNotifications('promos')) {
            return null;
        }

        $values = $this->baseVariables($user);
        $wantsSms = $broadcast->send_sms && $broadcast->sms_text && $user->phone
            && (!$isPromo || $user->wantsNotifications('sms'));

        return $this->persist(
            key: 'broadcast',
            user: $user,
            category: $isPromo ? NotificationCategory::PROMO : NotificationCategory::SYSTEM,
            marketing: $isPromo,
            title: $this->render($broadcast->title, $values),
            body: $this->render($broadcast->body, $values),
            path: $broadcast->path ?: '/',
            channel: $isPromo ? 'promos' : 'general',
            smsText: $wantsSms ? $this->render((string) $broadcast->sms_text, $values) : null,
            dedupeKey: "broadcast:{$broadcast->id}:{$user->id}",
            variant: 0,
            broadcastId: $broadcast->id,
        );
    }

    /**
     * Um pedido feito até 48h depois de uma notificação conta como
     * resultado dessa notificação — é o que o painel mostra como
     * "conversão" de cada campanha.
     */
    public function markConversion(GasOrder $order): void
    {
        if (!$order->user_id) {
            return;
        }

        CampaignDelivery::where('user_id', $order->user_id)
            ->whereNull('converted_order_id')
            ->where('sent_at', '>=', now()->subHours(48))
            ->latest('sent_at')
            ->first()
            ?->update(['converted_order_id' => $order->id, 'converted_at' => now()]);
    }

    public function isQuietHours(?Carbon $at = null): bool
    {
        $hour = ($at ?? now())->copy()->setTimezone($this->timezone())->hour;

        return $hour >= (int) config('kerugas.notifications.quiet_start', 21)
            || $hour < (int) config('kerugas.notifications.quiet_end', 8);
    }

    public function timezone(): string
    {
        return (string) config('kerugas.notifications.timezone', 'Africa/Luanda');
    }

    /** Primeiro nome, para os textos ficarem próximos ("Olá, Maria!"). */
    public function firstName(User $user): string
    {
        $first = explode(' ', trim((string) $user->name))[0] ?? '';

        return $first !== '' ? $first : 'cliente';
    }

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $text, array $values): string
    {
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function eligible(User $user, array $definition, bool $respectQuietHours = true): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }

        $roles = array_map(fn (UserRole $role) => $role->value, $definition['roles']);
        if (!in_array($user->role?->value, $roles, true)) {
            return false;
        }

        if ($definition['preference'] && !$user->wantsNotifications($definition['preference'])) {
            return false;
        }

        if ($respectQuietHours && $this->isQuietHours()) {
            return false;
        }

        if ($definition['marketing']) {
            $sentThisWeek = CampaignDelivery::where('user_id', $user->id)
                ->where('marketing', true)
                ->where('sent_at', '>=', now()->subDays(7))
                ->count();

            if ($sentThisWeek >= self::MARKETING_WEEKLY_CAP) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function baseVariables(User $user): array
    {
        return [
            'nome' => $this->firstName($user),
            'central' => (string) config('kerugas.contact.central_phone', '+244923000000'),
        ];
    }

    /** A mesma pessoa recebe sempre a mesma variante (teste A/B estável). */
    private function variantFor(User $user, string $key, int $count): int
    {
        return $count <= 1 ? 0 : (int) (crc32($key . ':' . $user->id) % $count);
    }

    private function persist(
        string $key,
        User $user,
        NotificationCategory $category,
        bool $marketing,
        string $title,
        string $body,
        string $path,
        string $channel,
        ?string $smsText,
        ?string $dedupeKey,
        int $variant,
        ?int $broadcastId = null,
    ): ?CampaignDelivery {
        try {
            return DB::transaction(function () use ($key, $user, $category, $marketing, $title, $body, $path, $channel, $smsText, $dedupeKey, $variant, $broadcastId) {
                $delivery = CampaignDelivery::create([
                    'campaign_key' => $key,
                    'broadcast_id' => $broadcastId,
                    'user_id' => $user->id,
                    'dedupe_key' => $dedupeKey,
                    'variant' => $variant,
                    'marketing' => $marketing,
                    'sms_status' => $smsText !== null ? 'QUEUED' : null,
                    'sent_at' => now(),
                ]);

                // O observer desta notificação trata do push (telemóvel e
                // navegador), depois de a transação confirmar.
                $notification = AppNotification::create([
                    'user_id' => $user->id,
                    'category' => $category,
                    'title' => mb_substr($title, 0, 190),
                    'body' => $body,
                    'data' => [
                        'campaign' => $key,
                        'delivery_id' => $delivery->id,
                        'path' => $path,
                        'channel' => $channel,
                    ],
                ]);

                $delivery->update(['app_notification_id' => $notification->id]);

                if ($smsText !== null) {
                    SendCampaignSmsJob::dispatch($delivery->id, $smsText)->afterCommit();
                }

                return $delivery;
            });
        } catch (UniqueConstraintViolationException) {
            // Duas execuções em simultâneo com a mesma chave: a outra ganhou.
            return null;
        }
    }
}
