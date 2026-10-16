<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\NewContactMessageMail;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Formulário de Contacto público do site (Setembro 2026) — substitui o
 * protótipo que só fazia `setSent(true)` no frontend sem enviar nada.
 *
 * ORDEM DELIBERADA: guarda sempre em `contact_messages` PRIMEIRO, só
 * depois tenta enviar o email. Um `.env` de produção com SMTP mal
 * configurado (o mesmo tipo de problema já visto com a Kamba) nunca
 * deve fazer perder a mensagem do cliente — ela fica visível em
 * /admin/contact-messages mesmo que o email nunca chegue. O envio en-
 * fileira (`NewContactMessageMail implements ShouldQueue`), por isso
 * uma falha nele nunca chega a este controller como exceção; mesmo
 * assim, o `try/catch` cobre a falha rara de nem conseguir enfileirar
 * (ex.: driver de fila em baixo).
 */
class ContactController extends Controller
{
    public function store(StoreContactMessageRequest $request): JsonResponse
    {
        $data = $request->validated();

        $contactMessage = ContactMessage::create([
            'name' => $data['name'],
            'contact' => $data['contact'],
            'message' => $data['message'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            Mail::to(config('kerugas.contact.recipient'))->send(new NewContactMessageMail($contactMessage));
        } catch (Throwable $e) {
            Log::channel('single')->error('[ContactController] falha ao enfileirar o email de contacto', [
                'contact_message_id' => $contactMessage->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mensagem enviada com sucesso. A nossa equipa responde o mais breve possível.',
        ], 201);
    }
}
