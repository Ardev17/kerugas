<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contact' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
            // Honeypot anti-spam (Setembro 2026): campo invisível para
            // humanos (escondido por CSS no frontend), que um bot que
            // preenche todos os inputs de um formulário tende a
            // preencher. Nunca obrigatório — a maioria dos visitantes
            // nunca o envia — mas TEM de vir vazio quando presente.
            // Mais barato e sem dependência externa do que um captcha,
            // suficiente para o volume esperado deste formulário.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            // Mensagem genérica de propósito — nunca revelar a um bot
            // que o campo é um honeypot.
            'website.prohibited' => 'Não foi possível validar o formulário.',
        ];
    }
}
