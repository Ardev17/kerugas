<?php

namespace Tests\Support;

use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;

/**
 * Fake do envio de push para testes — nunca contacta o Firebase, só
 * regista o que lhe é pedido. `$invalidTokens` simula telemóveis onde o
 * app já foi desinstalado.
 */
class FakePushSender implements PushSender
{
    /** @var array<int, array{token: string, message: PushMessage}> */
    public array $sent = [];

    /**
     * @param  array<int, string>  $invalidTokens
     */
    public function __construct(private array $invalidTokens = [])
    {
    }

    public function send(string $deviceToken, PushMessage $message): PushResult
    {
        if (in_array($deviceToken, $this->invalidTokens, true)) {
            return PushResult::INVALID_TOKEN;
        }

        $this->sent[] = ['token' => $deviceToken, 'message' => $message];

        return PushResult::SENT;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
