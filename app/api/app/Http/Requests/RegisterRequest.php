<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validação de registo (secção 17). Telefone é sempre obrigatório;
 * email pode ser obrigatório ou opcional conforme configuração —
 * na Fase 1, mantido opcional (decisão documentada no README).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'province_id' => ['required', 'exists:provinces,id'],
            'municipality_id' => ['required', 'exists:municipalities,id'],
            // Localização exata — OPCIONAL. O utilizador só a partilha se
            // clicar no botão "aperte este botão, se estiver apenas em sua
            // casa" no registo. Ajuda a traçar rota de entrega quando o
            // utilizador pede gás e não consegue atender chamada/ler SMS.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'O número de telefone é obrigatório.',
            'password.confirmed' => 'A confirmação da password não coincide.',
            'password.min' => 'A password deve ter pelo menos 8 caracteres.',
        ];
    }
}
