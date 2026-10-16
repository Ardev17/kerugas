<?php

namespace App\Services\Push;

/**
 * Usado quando FCM_CREDENTIALS_PATH não está definido (desenvolvimento,
 * ou produção antes de criar o projeto Firebase). Não envia nada — as
 * notificações continuam a aparecer dentro do app/site pelo sino.
 */
class NullPushSender implements PushSender
{
    public function send(string $deviceToken, PushMessage $message): PushResult
    {
        return PushResult::FAILED;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
