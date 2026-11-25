<?php

namespace App\Http\Requests;

use App\Enums\QueueLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gas_available' => ['required', 'boolean'],
            'queue_level' => ['nullable', new Enum(QueueLevel::class)],
        ];
    }
}
