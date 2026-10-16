<?php

namespace App\Listeners;

use App\Events\GasAvailabilityChanged;
use App\Models\Alert;
use App\Models\AppNotification;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ouve GasAvailabilityChanged e notifica os utilizadores com alertas
 * ativos que correspondam ao posto (diretamente, ou por município +
 * raio de distância). Implementa cooldown para não notificar em
 * excesso o mesmo utilizador sobre o mesmo posto (secção 22/58).
 *
 * BUG CORRIGIDO: mesma correção do NotifyAdminsOfNewOrderListener —
 * `ShouldQueue` sem worker a correr não trazia benefício e podia
 * deixar uma exceção aqui dentro escapar ao try/catch do
 * StationStatusService, rebentando a resposta de uma atualização de
 * estado que na verdade já tinha sido guardada com sucesso.
 */
class DispatchStationAlertsListener
{
    private const COOLDOWN_MINUTES = 30;

    public function __construct(private readonly SmsProviderInterface $smsProvider)
    {
    }

    public function handle(GasAvailabilityChanged $event): void
    {
        try {
            $station = $event->station;

            $matchingAlerts = Alert::query()
                ->where('active', true)
                ->where(function ($query) use ($station) {
                    $query->where('station_id', $station->id)
                        ->orWhere('municipality_id', $station->municipality_id);
                })
                ->where(function ($query) {
                    $query->whereNull('last_triggered_at')
                        ->orWhere('last_triggered_at', '<=', now()->subMinutes(self::COOLDOWN_MINUTES));
                })
                ->with('user')
                ->get();

            foreach ($matchingAlerts as $alert) {
                $this->notifyForAlert($alert, $station);
            }
        } catch (Throwable $e) {
            Log::channel('single')->warning('[DispatchStationAlertsListener] Falha ao notificar alertas (não crítico)', [
                'station_id' => $event->station->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyForAlert(Alert $alert, $station): void
    {
        if (!$alert->user) {
            return;
        }

        // DECISÃO TÉCNICA: alertas por município notificam todo o município
        // que corresponde. Refinar por raio de distância (max_distance_km)
        // exigiria guardar a localização de referência do próprio alerta
        // (não apenas o município) — deixado para uma iteração futura,
        // documentado aqui em vez de implementar um cálculo aproximado
        // que poderia dar falsos negativos/positivos.

        DB::transaction(function () use ($alert, $station) {
            $title = 'Gás disponível';
            $body = "O posto \"{$station->name}\" tem gás disponível agora.";

            if (in_array('in_app', $alert->channels, true)) {
                AppNotification::create([
                    'user_id' => $alert->user_id,
                    'category' => 'availability',
                    'title' => $title,
                    'body' => $body,
                    'data' => ['station_id' => $station->id],
                ]);
            }

            if (in_array('sms', $alert->channels, true) && $alert->user->phone) {
                // NUNCA incluir um link aqui (ex: config('app.url')) — a
                // KambaSMS (e outras operadoras angolanas) bloqueiam
                // automaticamente qualquer SMS com http(s)://, www. ou um
                // domínio, mesmo em conteúdo transacional legítimo (ver
                // "Boas Práticas para o Sender ID", Setembro 2026). A versão
                // anterior desta mensagem incluía a URL da app e teria sido
                // sempre recusada por um provedor real.
                $this->smsProvider->send(
                    $alert->user->phone,
                    "KERUGAS: {$body}"
                );
            }

            $alert->update(['last_triggered_at' => now()]);
        });
    }
}
