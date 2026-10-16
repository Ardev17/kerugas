<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasHoneypot;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    // Honeypot (Outubro 2026): ver PasswordResetController::sendResetLink —
    // aqui a armadilha não dá erro, dá o mesmo "sucesso" de sempre.
    use HasHoneypot;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}
