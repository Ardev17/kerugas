<?php

namespace App\Jobs;

use App\Models\CampaignDelivery;
use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\SmsTextNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMS de uma campanha/envio manual (Setembro 2026). Uma só tentativa de
 * propósito: um SMS repetido por engano é dinheiro gasto duas vezes e um
 * cliente incomodado. O texto sai sempre em GSM-7 (um só SMS cobrado).
 */
class SendCampaignSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId, public readonly string $text)
    {
    }

    public function handle(SmsProviderInterface $provider, SmsTextNormalizer $normalizer): void
    {
        $delivery = CampaignDelivery::with('user')->find($this->deliveryId);
        $phone = $delivery?->user?->phone;

        if (!$delivery || !$phone || $delivery->sms_status !== 'QUEUED') {
            return;
        }

        try {
            $sent = $provider->send($phone, $normalizer->toGsm7($this->text));
            $delivery->update(['sms_status' => $sent ? 'SENT' : 'FAILED']);
        } catch (Throwable $e) {
            $delivery->update(['sms_status' => 'FAILED']);
            Log::channel('single')->warning('[SendCampaignSmsJob] SMS de campanha falhou', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
