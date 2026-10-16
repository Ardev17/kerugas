<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SmsDailyLimitExceededException;
use App\Models\SmsDispatch;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\Sms\SmsDispatchService;
use App\Services\Sms\SmsTemplateRenderer;
use App\Services\Sms\SmsTextNormalizer;

/**
 * Envia um SMS de subscrição a partir de um template da Central (pelo
 * `code`), sempre renderizado no servidor e em GSM-7. Devolve `null`
 * quando o template não existe/está desativado ou o limite diário do
 * remetente foi atingido — quem chama decide o que dizer ao utilizador;
 * nunca rebenta a ação principal (ex.: a ativação já gravada).
 */
class SubscriptionSmsService
{
    public function __construct(
        private readonly SmsDispatchService $dispatcher,
        private readonly SmsTemplateRenderer $renderer,
        private readonly SmsTextNormalizer $smsText,
    ) {
    }

    /** @param array<string, string> $context */
    public function send(User $by, string $templateCode, string $recipient, array $context): ?SmsDispatch
    {
        $template = SmsTemplate::where('code', $templateCode)->where('active', true)->first();
        if (!$template) {
            return null;
        }

        $message = $this->smsText->toGsm7($this->renderer->render($template->content, $context));

        try {
            return $this->dispatcher->dispatch($by, $recipient, $message, $template);
        } catch (SmsDailyLimitExceededException) {
            return null;
        }
    }
}
