<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Notificação por email de uma nova mensagem de contacto (Setembro
 * 2026). Implementa ShouldQueue — o docker-compose já corre um worker
 * dedicado (`queue`, `php artisan queue:work`) — para que um SMTP lento
 * ou em baixo nunca atrase a resposta ao visitante que preencheu o
 * formulário; a mensagem já ficou guardada em `contact_messages` antes
 * disto ser despachado (ver ContactController::store()).
 */
class NewContactMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ContactMessage $contactMessage)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Novo contacto pelo site — ' . $this->contactMessage->name)
            ->view('emails.contact-message');
    }
}
