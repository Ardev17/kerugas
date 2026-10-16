<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Início manual de um atendimento na Central de Operações. O
 * `original_message`, quando preenchido, é sempre texto introduzido
 * manualmente pelo admin a partir do que leu no telefone físico da
 * Central — nunca recebido automaticamente pelo sistema.
 */
class StoreOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate feito pelo middleware `permission:operations.manage`
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'municipality_id' => ['nullable', 'exists:municipalities,id', 'required_with:province_id'],
            'original_message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
