<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionType;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionNotifier;
use Illuminate\Console\Command;

/**
 * Lembrete automático aos operadores (Outubro 2026):
 *   php artisan kerugas:subscriptions-check
 * Avisa, UMA vez, o operador de um posto cuja subscrição termina nos
 * próximos 7 dias e ainda não foi renovada. Não liga nem desliga o
 * analytics — esse interruptor continua manual (Super Admin).
 */
class CheckSubscriptionsCommand extends Command
{
    protected $signature = 'kerugas:subscriptions-check {--days=7 : Antecedência do lembrete}';

    protected $description = 'Avisa os operadores cuja subscrição do posto está prestes a terminar';

    public function handle(SubscriptionNotifier $notifier): int
    {
        $days = max(1, (int) $this->option('days'));
        $sent = 0;

        Subscription::query()
            ->with('station.operator')
            ->covering()
            ->where('type', SubscriptionType::STATION_ANALYTICS)
            ->whereNull('reminder_sent_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->get()
            ->each(function (Subscription $s) use ($notifier, &$sent) {
                // Já renovada? (existe outro período a começar quando este acaba)
                $renewed = Subscription::query()
                    ->where('type', SubscriptionType::STATION_ANALYTICS)
                    ->where('station_id', $s->station_id)
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->where('expires_at', '>', $s->expires_at)
                    ->exists();

                if (!$renewed && $s->station && $notifier->expiringSoon($s->station, $s)) {
                    $sent++;
                }

                // Marca sempre, para não voltar a avaliar este período.
                $s->update(['reminder_sent_at' => now()]);
            });

        $this->info("Lembretes enviados: {$sent}");

        return self::SUCCESS;
    }
}
