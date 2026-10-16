<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validação de criação/edição de posto pelo admin (secção 96/97).
 * Coordenadas sempre validadas dentro dos limites geográficos válidos.
 *
 * LACUNA CORRIGIDA: `operator_id` nem sequer existia aqui — o campo
 * ficava sempre em branco na criação de um posto, e não havia nenhuma
 * forma, através do formulário do posto, de dizer "esta pessoa é o
 * operador deste posto". A relação já existia na base de dados
 * (`stations.operator_id`) desde a Etapa 11, mas só era preenchida
 * manualmente via seeder — nunca através da interface do Admin.
 */
class StoreStationRequest extends FormRequest
{
    /**
     * O gate de permissão é feito pelo middleware da rota. Aqui, ao EDITAR,
     * confirma-se primeiro que o posto está no âmbito do Admin: sem isto, a
     * validação dos campos corria antes e um posto de outra província
     * respondia 422 (com erros de campos) em vez de 403 — resposta
     * diferente consoante o posto exista ou não no âmbito, e diferente de
     * todas as outras rotas de postos.
     */
    public function authorize(): bool
    {
        $station = $this->route('station');
        $user = $this->user();

        if ($station instanceof Station && $user?->role === UserRole::ADMIN) {
            return (int) $station->province_id === (int) $user->province_id;
        }

        return true;
    }

    public function rules(): array
    {
        $stationId = $this->route('station')?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'province_id' => ['required', 'exists:provinces,id'],
            'municipality_id' => ['required', 'exists:municipalities,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'reference_point' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'sms_phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('stations', 'sms_phone')->ignore($stationId),
            ],
            'opening_hours' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:ACTIVE,INACTIVE,PENDING,SUSPENDED'],
            'operator_id' => ['nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'sms_phone.unique' => 'Este número já está associado a outro posto.',
            'latitude.between' => 'Latitude inválida (deve estar entre -90 e 90).',
            'longitude.between' => 'Longitude inválida (deve estar entre -180 e 180).',
        ];
    }

    /**
     * Três regras de integridade que a validação declarativa acima
     * não consegue expressar sozinha:
     *
     * 1. Só o SUPER_ADMIN pode atribuir ou alterar o operador de um
     *    posto — pedido explícito do cliente. Um Admin comum pode
     *    continuar a editar o resto do posto (nome, morada, estado...)
     *    à vontade; só fica bloqueado se tentar MUDAR o campo
     *    operador especificamente. Comparamos sempre com o valor
     *    atual do posto, não com "enviou algo" — assim o formulário
     *    reenviar o mesmo operador_id que já lá estava (o caso normal
     *    ao editar qualquer outro campo) nunca é tratado como uma
     *    tentativa de alteração.
     * 2. Um utilizador não pode operar dois postos ao mesmo tempo —
     *    a relação `User::operatedStation()` é `hasOne`; permitir
     *    dois postos com o mesmo `operator_id` deixaria essa relação
     *    ambígua (qual dos dois o `hasOne` devolveria?).
     * 3. O operador escolhido não pode já ser Admin/Super Admin/
     *    Moderador — evita atribuir sem querer um posto a uma conta
     *    de gestão, o que criaria um conflito de papéis confuso.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertProvinceScope($validator);

            $station = $this->route('station');
            $currentOperatorId = $station?->operator_id;
            $submittedOperatorId = $this->input('operator_id') ? (int) $this->input('operator_id') : null;

            $isChangingOperator = $submittedOperatorId !== $currentOperatorId;

            if ($isChangingOperator && $this->user()->role !== UserRole::SUPER_ADMIN) {
                $validator->errors()->add('operator_id', 'Só o Super Admin pode atribuir ou alterar o operador de um posto.');

                return;
            }

            if (!$submittedOperatorId) {
                return;
            }

            $operator = User::find($submittedOperatorId);

            if (!$operator) {
                return; // já apanhado pela regra 'exists'
            }

            if (in_array($operator->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::MODERATOR], true)) {
                $validator->errors()->add('operator_id', 'Este utilizador já tem um papel administrativo e não pode ser operador de posto.');

                return;
            }

            $alreadyOperatesOtherStation = $operator->operatedStation && $operator->operatedStation->id !== $station?->id;

            if ($alreadyOperatesOtherStation) {
                $validator->errors()->add(
                    'operator_id',
                    "Este utilizador já é operador de \"{$operator->operatedStation->name}\". Remova essa associação primeiro."
                );
            }
        });
    }

    /**
     * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só
     * pode criar/editar postos DENTRO da sua própria província —
     * nunca pode escolher outra província no formulário, nem um
     * município que pertença a outra província (o que criaria um
     * posto com província/município inconsistentes, mesmo que ambos
     * os campos sejam individualmente válidos). SUPER_ADMIN não tem
     * esta restrição.
     *
     * Isto é sobre os DADOS submetidos (por isso vive aqui, na
     * validação, com erro 422) — é diferente de
     * `StationAdminController::assertProvinceAccess()`, que verifica
     * se o ADMIN pode sequer tocar num posto JÁ EXISTENTE de outra
     * província (erro 403, uma questão de autorização).
     */
    private function assertProvinceScope(Validator $validator): void
    {
        if ($this->user()->role !== UserRole::ADMIN) {
            return;
        }

        $adminProvinceId = $this->user()->province_id;
        $submittedProvinceId = $this->input('province_id') ? (int) $this->input('province_id') : null;

        if ($submittedProvinceId !== (int) $adminProvinceId) {
            $validator->errors()->add('province_id', 'Só pode criar ou editar postos da sua própria província.');

            return; // evita erro em cascata sobre o município se a província já está errada
        }

        $municipalityId = $this->input('municipality_id');
        $municipality = $municipalityId ? Municipality::find($municipalityId) : null;

        if ($municipality && (int) $municipality->province_id !== (int) $adminProvinceId) {
            $validator->errors()->add('municipality_id', 'Este município não pertence à sua província.');
        }
    }
}
