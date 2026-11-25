<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\CampaignDelivery;
use App\Services\Campaigns\BroadcastAudience;
use App\Services\Campaigns\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entrega um envio manual do Super Admin a todo o público escolhido.
 *
 * Por fatias: cada job trata no máximo SLICE pessoas (poucos segundos) e
 * agenda a fatia seguinte a partir do último id. Um job único de muitos
 * minutos seria apanhado outra vez pela fila ao fim de `retry_after`
 * (90 s por omissão no Laravel 11) e correria em duplicado, em paralelo.
 * Assim cada job termina muito antes disso, e:
 *
 * - repetir é seguro: cada pessoa tem a chave "broadcast:{id}:{user}", por
 *   isso quem já recebeu não recebe de novo (nem paga outro SMS);
 * - cancelar a meio funciona: cada fatia confirma o estado antes de começar.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SLICE = 500;

    public int $tries = 3;

    /** Bem abaixo do retry_after da fila (90 s). */
    public int $timeout = 80;

    public function __construct(public readonly int $broadcastId, public readonly int $afterUserId = 0)
    {
    }

    public function handle(CampaignService $campaigns): void
    {
        $broadcast = Broadcast::find($this->broadcastId);
        if (!$broadcast || in_array($broadcast->status, ['CANCELLED', 'SENT', 'FAILED'], true)) {
            return;
        }

        if ($broadcast->status !== 'SENDING' || !$broadcast->started_at) {
            $broadcast->update(['status' => 'SENDING', 'started_at' => $broadcast->started_at ?? now()]);
        }

        $users = BroadcastAudience::query($broadcast->audience, $broadcast->province_id, $broadcast->municipality_id)
            ->where('users.id', '>', $this->afterUserId)
            ->orderBy('users.id')
            ->limit(self::SLICE)
            ->get();

        foreach ($users as $user) {
            try {
                $campaigns->deliverBroadcast($broadcast, $user);
            } catch (Throwable $e) {
                Log::channel('single')->warning('[SendBroadcastJob] Falha a entregar a uma pessoa (as restantes continuam)', [
                    'broadcast_id' => $broadcast->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($users->count() === self::SLICE) {
            self::dispatch($broadcast->id, (int) $users->last()->id);

            return;
        }

        $deliveries = CampaignDelivery::where('broadcast_id', $broadcast->id);

        // Só fecha se ninguém o cancelou entretanto.
        Broadcast::where('id', $broadcast->id)->where('status', 'SENDING')->update([
            'status' => 'SENT',
            'finished_at' => now(),
            'recipients_count' => (clone $deliveries)->count(),
            'sms_count' => (clone $deliveries)->whereNotNull('sms_status')->count(),
        ]);
    }

    /** Só marca falha se ainda estava a enviar (nunca por cima de SENT/CANCELLED). */
    public function failed(Throwable $e): void
    {
        Broadcast::where('id', $this->broadcastId)
            ->where('status', 'SENDING')
            ->update([
                'status' => 'FAILED',
                'finished_at' => now(),
                'recipients_count' => CampaignDelivery::where('broadcast_id', $this->broadcastId)->count(),
            ]);
    }
}
