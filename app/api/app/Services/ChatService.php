<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Chat por município (pedido explícito do cliente, Setembro 2026): um
 * lugar para admins, super admins e condutores perguntarem coisas como
 * "alguém conhece o local X ou Y do município Z?" quando a
 * geolocalização exata de uma entrega falha.
 */
class ChatService
{
    /**
     * Envia uma mensagem para o município indicado, depois de validar
     * que o remetente tem, de facto, acesso a esse município — a mesma
     * regra de visibilidade de `scopeVisibleMunicipalities()`, aplicada
     * aqui do lado da ESCRITA (nunca confiar só no filtro de leitura).
     *
     * @throws HttpException 403 se o utilizador não tiver acesso a este município.
     */
    public function send(User $sender, int $municipalityId, string $body): ChatMessage
    {
        $municipality = Municipality::findOrFail($municipalityId);

        $this->assertCanAccessMunicipality($sender, $municipality);

        $message = ChatMessage::create([
            'user_id' => $sender->id,
            'province_id' => $municipality->province_id,
            'municipality_id' => $municipality->id,
            'body' => $body,
        ]);

        // Mesma resiliência de GasOrderService::create — o Reverb offline
        // nunca deve impedir a mensagem de ficar guardada.
        try {
            event(new ChatMessageSent($message));
        } catch (Throwable $e) {
            Log::channel('single')->warning('[ChatService] Falha ao transmitir mensagem em tempo real (não crítico)', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }

    /**
     * @throws HttpException 403 se o utilizador não tiver acesso a este município.
     */
    public function assertCanAccessMunicipality(User $user, Municipality $municipality): void
    {
        if ($user->role === UserRole::SUPER_ADMIN) {
            return;
        }

        if ($user->role === UserRole::DRIVER) {
            abort_unless((int) $user->municipality_id === $municipality->id, 403, 'Só pode participar no chat do seu próprio município.');

            return;
        }

        if ($user->role === UserRole::ADMIN) {
            abort_unless((int) $user->province_id === (int) $municipality->province_id, 403, 'Este município pertence a outra província — fora do âmbito da sua conta de administrador.');

            return;
        }

        abort(403, 'O seu papel não tem acesso ao chat.');
    }
}
