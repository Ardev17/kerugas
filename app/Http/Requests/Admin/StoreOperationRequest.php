<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // O gate principal é o middleware `permission:operations.manage`.
        // Outubro 2026: um ADMIN fica preso à sua província também aqui —
        // sem província atribuída não atende ninguém (nunca "sem filtro =
        // vê tudo"), tal como no resto do painel.
        $user = $this->user();

        // Super Admin Provincial (Outubro 2026): mesma regra do Admin.
        return $user->scopedProvinceId() !== 0;
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'A sua conta de Admin ainda não tem província atribuída — contacte um Super Admin.');
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();

        // O Admin não escolhe a província: é sempre a da sua conta, mesmo
        // que o pedido traga outra (o ecrã já a preenche e bloqueia).
        if ($user->scopedProvinceId()) {
            $this->merge(['province_id' => $user->scopedProvinceId()]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            // O município tem de pertencer à província indicada.
            'municipality_id' => [
                'nullable',
                Rule::exists('municipalities', 'id')->where(fn ($q) => $q->where('province_id', $this->input('province_id'))),
                'required_with:province_id',
            ],
            'original_message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
