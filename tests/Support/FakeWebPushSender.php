<?php

namespace Tests\Support;

use App\Models\OrderPushSubscription;
use App\Models\WebPushSubscription;
use App\Services\Push\PushMessage;
use App\Services\Push\WebPushSender;

/**
 * Fake do Web Push para testes — nunca contacta serviços de push reais.
 * `$expiredEndpoints` simula navegadores cuja subscrição já expirou.
 */
class FakeWebPushSender extends WebPushSender
{
    /** @var array<int, array{endpoint: string, message: PushMessage}> */
    public array $sent = [];

    /** @param array<int, string> $expiredEndpoints */
    public function __construct(private array $expiredEndpoints = [])
    {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function send(WebPushSubscription|OrderPushSubscription $subscription, PushMessage $message): bool
    {
        if (in_array($subscription->endpoint, $this->expiredEndpoints, true)) {
            return false;
        }

        $this->sent[] = ['endpoint' => $subscription->endpoint, 'message' => $message];

        return true;
    }
}
