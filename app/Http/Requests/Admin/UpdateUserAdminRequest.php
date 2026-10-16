<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateUserAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', new Enum(UserRole::class)],
            'status' => ['sometimes', 'in:ACTIVE,SUSPENDED'],
            'province_id' => ['sometimes', 'nullable', 'integer', 'exists:provinces,id'],
            // Condutor (Setembro 2026) — mesma restrição de reatribuição
            // que já existia para province_id: só o Super Admin pode
            // mudar o município de alguém (ver assertAdminCannotReassignProvince).
            'municipality_id' => ['sometimes', 'nullable', 'integer', 'exists:municipalities,id'],
            // Limite diário de SMS (Setembro 2026) — `null` = sem limite.
            // Restrição a SUPER_ADMIN aplicada em withValidator() abaixo,
            // não aqui: a regra depende de quem está autenticado, que as
            // regras declarativas de FormRequest não veem diretamente.
            'sms_daily_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Outubro 2026: só o Super Admin liga/desliga, e só em contas de Admin.
            'can_promote_users' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Um ADMIN sem província atribuída não tem forma de ver pedidos de
     * gás — o AdminOrderController filtra pela `province_id` da conta.
     * Em vez de deixar isso descobrir-se em runtime como "lista sempre
     * vazia" (bug silencioso, difícil de diagnosticar para quem gere
     * utilizadores), bloqueia-se aqui, na origem: nunca se guarda um
     * ADMIN sem província, seja porque está a ser promovido agora, seja
     * porque já era ADMIN e este pedido só mexe noutro campo.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User|null $target */
            $target = $this->route('user');

            $this->assertAdminCannotReassignProvince($validator, $target);
            $this->assertMunicipalityMatchesProvince($validator, $target);
            $this->assertAdminCannotSetSmsDailyLimit($validator);
            $this->assertOnlySuperAdminSetsPromotePermission($validator, $target);

            $resultingRole = $this->has('role')
                ? UserRole::tryFrom((string) $this->input('role'))
                : $target?->role;

            if (!in_array($resultingRole, [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::DRIVER], true)) {
                return;
            }

            $resultingProvinceId = $this->has('province_id')
                ? $this->input('province_id')
                : $target?->province_id;

            if (!$resultingProvinceId) {
                $validator->errors()->add(
                    'province_id',
                    'Uma conta de Admin/Super Admin Provincial/Condutor tem de ter uma província atribuída — sem isso, nunca conseguiria ver pedidos de gás.'
                );
            }

            // Condutor (Setembro 2026): também precisa de município —
            // é o que decide que pedidos ele recebe, ao contrário do
            // Admin, que é escopado só por província.
            if ($resultingRole === UserRole::DRIVER) {
                $resultingMunicipalityId = $this->has('municipality_id')
                    ? $this->input('municipality_id')
                    : $target?->municipality_id;

                if (!$resultingMunicipalityId) {
                    $validator->errors()->add(
                        'municipality_id',
                        'Uma conta de Condutor tem de ter um município atribuído — é isso que determina que pedidos recebe.'
                    );
                }
            }
        });
    }

    /**
     * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só
     * gere utilizadores DA SUA própria província — mudar a província
     * de um utilizador seria uma forma indireta de contornar essa
     * restrição (mover alguém para dentro/fora do seu âmbito à
     * vontade). Por isso só o SUPER_ADMIN pode reatribuir a província
     * de um utilizador; um ADMIN pode reenviar o mesmo valor que já lá
     * estava (o caso normal ao editar qualquer outro campo), mas não
     * pode alterá-lo.
     */
    private function assertAdminCannotReassignProvince(Validator $validator, ?User $target): void
    {
        if ($this->user()->isGlobalSuperAdmin() || !$this->has('province_id')) {
            return; // só o Super Admin GERAL muda a província de alguém
        }

        $submittedProvinceId = $this->input('province_id') ? (int) $this->input('province_id') : null;
        $currentProvinceId = $target?->province_id;

        if ($submittedProvinceId !== $currentProvinceId) {
            $validator->errors()->add('province_id', 'Só o Super Admin geral pode alterar a província de um utilizador.');
        }
    }

    /**
     * O município (novo ou atual) tem de pertencer à província resultante —
     * sem isto, mudar só o município moveria um condutor para outra
     * província por uma porta lateral (Outubro 2026).
     */
    private function assertMunicipalityMatchesProvince(Validator $validator, ?User $target): void
    {
        if (!$this->has('municipality_id') || !$this->input('municipality_id')) {
            return;
        }

        $provinceId = $this->has('province_id') ? $this->input('province_id') : $target?->province_id;
        $municipalityProvince = \App\Models\Municipality::whereKey($this->input('municipality_id'))->value('province_id');

        if (!$provinceId || (int) $municipalityProvince !== (int) $provinceId) {
            $validator->errors()->add('municipality_id', 'O município escolhido não pertence à província deste utilizador.');
        }
    }

    /**
     * Limite diário de SMS (Setembro 2026) — controlo de custo definido
     * pelo Super Admin. Um Admin nunca deve conseguir alterar o seu
     * próprio limite (nem o de um colega) por esta via — anularia o
     * propósito do controlo.
     */
    private function assertAdminCannotSetSmsDailyLimit(Validator $validator): void
    {
        if ($this->user()->hasSuperPowers() || !$this->has('sms_daily_limit')) {
            return;
        }

        $validator->errors()->add('sms_daily_limit', 'Só o Super Admin pode alterar o limite diário de SMS de um utilizador.');
    }

    /**
     * Permissão de promover utilizadores (Outubro 2026): decidida pelo
     * Super Admin, conta a conta. Um Admin nunca a concede a si próprio
     * nem a um colega, e só faz sentido numa conta que seja (ou passe a
     * ser) Admin.
     */
    private function assertOnlySuperAdminSetsPromotePermission(Validator $validator, ?User $target): void
    {
        if (!$this->has('can_promote_users')) {
            return;
        }

        if (!$this->user()->hasSuperPowers()) {
            $validator->errors()->add('can_promote_users', 'Só o Super Admin pode conceder ou retirar a permissão de promover utilizadores.');

            return;
        }

        $resultingRole = $this->has('role') ? UserRole::tryFrom((string) $this->input('role')) : $target?->role;

        if ($this->boolean('can_promote_users') && $resultingRole !== UserRole::ADMIN) {
            $validator->errors()->add('can_promote_users', 'Esta permissão só se aplica a contas de Admin.');
        }
    }
}
