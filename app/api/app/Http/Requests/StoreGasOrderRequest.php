<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do pedido de gás. Campos obrigatórios conforme
 * Master Build Prompt, secção 204.
 */
class StoreGasOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pedido pode ser feito sem autenticação (secção 172),
        // protegido por rate limiting definido nas rotas.
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:20'],

            'province_id' => ['required', 'exists:provinces,id'],
            'municipality_id' => ['required', 'exists:municipalities,id'],
            'neighborhood' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'reference_point' => ['nullable', 'string', 'max:255'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_source' => ['required', 'in:GPS,MANUAL'],
            'location_accuracy' => ['nullable', 'numeric'],

            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'cylinder_type' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_name.required' => 'O nome é obrigatório.',
            'customer_phone.required' => 'O número de telefone é obrigatório.',
            'province_id.required' => 'Selecione a província.',
            'municipality_id.required' => 'Selecione o município.',
            'quantity.required' => 'Informe a quantidade.',
        ];
    }
}
