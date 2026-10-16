<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Criação administrativa de contas (pedido explícito do cliente,
 * Setembro 2026) — até agora só existia auto-registo (`/auth/register`,
 * sempre papel USER); esta é a primeira via para uma conta nascer já
 * com outro papel, criada por um Admin/Super Admin. A verificação de
 * QUEM pode criar QUE papel vive em `UserAdminController::store()`
 * (mesma lógica de `UpdateUserAdminRequest`/`PRIVILEGED_ROLES`) — este
 * Request só valida a forma dos dados.
 */
class StoreUserAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', new Enum(UserRole::class)],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
        ];
    }

    /**
     * Mesma regra de ADMIN (não pode ficar sem província) estendida a
     * DRIVER — sem província E município, um Condutor nunca aparece em
     * nenhuma listagem escopada (DriverAdminController) nem recebe
     * pedidos (o filtro é sempre por `municipality_id`).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $role = UserRole::tryFrom((string) $this->input('role'));

            if ($role === UserRole::ADMIN && !$this->input('province_id')) {
                $validator->errors()->add('province_id', 'Uma conta de Admin tem de ter uma província atribuída.');
            }

            if ($role === UserRole::DRIVER) {
                if (!$this->input('province_id')) {
                    $validator->errors()->add('province_id', 'Uma conta de Condutor tem de ter uma província atribuída.');
                }

                if (!$this->input('municipality_id')) {
                    $validator->errors()->add('municipality_id', 'Uma conta de Condutor tem de ter um município atribuído — é isso que determina que pedidos recebe.');
                }
            }
        });
    }
}
