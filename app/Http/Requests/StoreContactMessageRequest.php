<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasHoneypot;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    use HasHoneypot;

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
        ] + $this->honeypotRules(); // honeypot anti-spam — ver HasHoneypot
    }

    public function messages(): array
    {
        return $this->honeypotMessages();
    }
}
