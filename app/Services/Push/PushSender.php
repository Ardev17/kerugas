<?php

namespace App\Services\Push;

interface PushSender
{
    public function send(string $deviceToken, PushMessage $message): PushResult;

    /** `false` quando o push não está configurado neste ambiente. */
    public function isEnabled(): bool;
}
