<?php

namespace App\Services\Push;

/**
 * Uma notificação push, independente do fornecedor (FCM hoje).
 *
 * `path` é o ecrã do app a abrir quando a pessoa toca na notificação
 * (ex: "/driver", "/postos/12") — validado outra vez no app antes de
 * navegar (src/native/deepLinks.ts), nunca confiado cegamente.
 * `channel` corresponde a um canal de notificação Android criado pelo
 * app (src/native/push.ts): cada canal tem som/importância próprios e o
 * utilizador pode silenciá-los em separado nas definições do Android.
 */
final class PushMessage
{
    public const CHANNEL_ORDERS = 'orders';
    public const CHANNEL_ALERTS = 'alerts';
    public const CHANNEL_GENERAL = 'general';
    // Campanhas (Setembro 2026) — canais próprios, para quem desliga as
    // promoções no Android continuar a receber os lembretes e as dicas.
    public const CHANNEL_REMINDERS = 'reminders';
    public const CHANNEL_TIPS = 'tips';
    public const CHANNEL_PROMOS = 'promos';

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $path = '/',
        public readonly string $channel = self::CHANNEL_GENERAL,
        public readonly array $data = [],
    ) {
    }
}
