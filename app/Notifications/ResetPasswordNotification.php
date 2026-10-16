<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Template em português de Angola (secção 59: Mailables). Substitui o
 * template padrão em inglês do Laravel para o e-mail de reset de password.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, public readonly ?string $code = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url', config('app.url')) . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        $mail = (new MailMessage)
            ->subject('KERUGAS — Código para repor a palavra-passe' . ($this->code ? ': ' . $this->code : ''))
            ->greeting('Olá,')
            ->line('Recebemos um pedido para repor a palavra-passe da sua conta KERUGAS.');

        // Outubro 2026: o código serve para a app (escreve-se no próprio ecrã).
        if ($this->code) {
            $mail->line('O seu código de recuperação é:')
                ->line('**' . implode(' ', str_split($this->code, 3)) . '**')
                ->line('Válido durante ' . \App\Services\Account\PasswordResetCodeService::CODE_MINUTES . ' minutos. Nunca o partilhe com ninguém — a equipa KERUGAS nunca o pede.');
        }

        return $mail
            ->line('No computador, também pode usar o botão:')
            ->action('Repor palavra-passe', $url)
            ->line('Se não foi você quem pediu, ignore este email: a sua palavra-passe continua a mesma.')
            ->salutation('Equipa KERUGAS');
    }
}
