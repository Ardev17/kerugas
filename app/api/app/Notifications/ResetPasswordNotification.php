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

    public function __construct(private readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url', config('app.url')) . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('KERUGAS — Redefinição de password')
            ->greeting('Olá,')
            ->line('Recebemos um pedido para redefinir a password da sua conta KERUGAS.')
            ->action('Redefinir password', $url)
            ->line('Se não foi você quem solicitou, pode ignorar este email com segurança.')
            ->salutation('Equipa KERUGAS');
    }
}
