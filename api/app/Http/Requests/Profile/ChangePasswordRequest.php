<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\PasswordConfirmation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/** Alterar a palavra-passe no "Meu Perfil" (Outubro 2026). */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'A confirmação da nova palavra-passe não coincide.',
            'password.min' => 'A nova palavra-passe deve ter pelo menos 8 caracteres.',
            'password.letters' => 'A nova palavra-passe tem de ter letras.',
            'password.numbers' => 'A nova palavra-passe tem de ter números.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();

            $error = PasswordConfirmation::check($user, $this->input('current_password'));
            if ($error !== null) {
                $validator->errors()->add('current_password', $error);

                return;
            }

            if (Hash::check((string) $this->input('password'), $user->password)) {
                $validator->errors()->add('password', 'A nova palavra-passe tem de ser diferente da atual.');
            }
        });
    }
}
