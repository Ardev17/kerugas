<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Notificação por email de uma nova mensagem de contacto (Setembro
 * 2026). Implementa ShouldQueue,o docker-compose já corre um worker
 * dedicado (`queue`, `php artisan queue:work`),para que um SMTP lento
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
            ->subject('Novo contacto pelo site,' . $this->assuntoSeguro($this->contactMessage->name))
            ->view('emails.contact-message');
    }

    /**
     * Limpa o nome antes de o pôr no cabeçalho Subject (Novembro 2026).
     *
     * O nome vem do formulário público. A validação já recusa caracteres
     * de controlo (ver App\Rules\SemCaracteresDeControlo), mas o assunto
     * é um cabeçalho de email e os dados podem chegar aqui por outra via
     *,uma importação, um registo antigo gravado antes desta regra. Como
     * o Laravel 11 está em fim de vida e não recebe a correcção da
     * CVE-2026-48019, limpamos também no ponto de uso.
     */
    private function assuntoSeguro(?string $nome): string
    {
        $limpo = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]+/u', ' ', (string) $nome);

        // preg_replace devolve null se o valor não for UTF-8 válido
        if ($limpo === null) {
            return 'contacto';
        }

        $limpo = trim(preg_replace('/\s+/u', ' ', $limpo) ?? '');

        return $limpo === '' ? 'contacto' : mb_substr($limpo, 0, 150);
    }
}
