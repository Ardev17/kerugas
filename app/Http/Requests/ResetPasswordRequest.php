<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Link do email (site) OU código de 6 dígitos (app) — Outubro 2026.
            'token' => ['required_without:code', 'nullable', 'string', 'max:255'],
            'code' => ['required_without:token', 'nullable', 'string', 'regex:/^\d{6}$/'],
            'email' => ['required', 'email', 'max:255'],
            // Mesma regra do "Meu perfil": pelo menos 8 caracteres, com letras e números.
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'O código tem 6 algarismos.',
            'code.required_without' => 'Escreva o código que recebeu por email.',
            'password.confirmed' => 'As palavras-passe não coincidem.',
        ];
    }
}
