<?php

namespace App\Http\Requests\Profile;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\PasswordConfirmation;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Meu Perfil" (Outubro 2026) — o que cada utilizador pode mudar em si.
 *
 * SEGURANÇA:
 * - Lista fechada de campos: papel, estado, permissões, limites de SMS,
 *   disponibilidade, etc. NUNCA passam por aqui (são ignorados).
 * - Telemóvel e email são os identificadores de login: mudá-los exige a
 *   palavra-passe atual (protege contra quem apanhe uma sessão aberta).
 * - Contas cuja província/município decidem o que podem ver ou fazer
 *   (Admin, Super Admin Provincial, Condutor) não mudam a própria
 *   localização — seria uma forma de fugir ao seu âmbito. Só o Super Admin
 *   geral as altera, em Utilizadores.
 */
class UpdateProfileRequest extends FormRequest
{
    /** Papéis cuja província/município é o âmbito de trabalho. */
    public const LOCATION_LOCKED_ROLES = [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::DRIVER];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim(preg_replace('/\s+/u', ' ', $this->input($field)));
                $this->merge([$field => $value === '' && $field === 'email' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:150', 'regex:/^[^<>{}]+$/u'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'province_id' => ['sometimes', 'nullable', 'integer', 'exists:provinces,id'],
            'municipality_id' => ['sometimes', 'nullable', 'integer', 'exists:municipalities,id'],
            'neighborhood_id' => ['sometimes', 'nullable', 'integer', 'exists:neighborhoods,id'],
            // Sem `sometimes`: assim `required_with` corre mesmo quando só vem um
            // dos dois (nunca se grava latitude sem longitude).
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'current_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'O nome não pode ter os caracteres < > { }.',
            'email.unique' => 'Este email já está associado a outra conta.',
            'email.email' => 'Email inválido.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User $user */
            $user = $this->user();

            // Telemóvel: normalizado e único.
            $phoneChanged = false;
            if ($this->has('phone') && !$validator->errors()->has('phone')) {
                $normalized = app(PhoneNumberService::class)->normalize((string) $this->input('phone'));
                if ($normalized === null) {
                    $validator->errors()->add('phone', 'Número de telefone angolano inválido (ex.: 923 123 456).');
                } elseif ($normalized !== $user->phone) {
                    if (User::withTrashed()->where('phone', $normalized)->where('id', '!=', $user->id)->exists()) {
                        $validator->errors()->add('phone', 'Este número já está associado a outra conta.');
                    }
                    $phoneChanged = true;
                }
            }

            $emailChanged = $this->has('email') && $this->input('email') !== $user->email;

            // Identificadores de login: exigem a palavra-passe atual.
            if ($phoneChanged || $emailChanged) {
                $error = PasswordConfirmation::check($user, $this->input('current_password'));
                if ($error !== null) {
                    $validator->errors()->add('current_password', $error === 'Confirme com a sua palavra-passe atual.'
                        ? 'Para mudar o telemóvel ou o email confirme com a sua palavra-passe atual.'
                        : $error);
                }
            }

            // Localização de trabalho: não se muda a si próprio.
            $provinceChanged = $this->has('province_id') && (int) $this->input('province_id') !== (int) $user->province_id;
            $municipalityChanged = $this->has('municipality_id') && (int) $this->input('municipality_id') !== (int) $user->municipality_id;

            if (in_array($user->role, self::LOCATION_LOCKED_ROLES, true) && ($provinceChanged || $municipalityChanged)) {
                $validator->errors()->add(
                    $provinceChanged ? 'province_id' : 'municipality_id',
                    'A sua província/município define a sua área de trabalho — só o Super Admin a pode alterar.'
                );

                return;
            }

            $this->checkNeighborhood($validator, $user);

            // O município tem de pertencer à província (só quando se mexe neles —
            // dados antigos inconsistentes nunca impedem de editar o nome).
            if (!$this->has('province_id') && !$this->has('municipality_id')) {
                return;
            }
            $provinceId = $this->has('province_id') ? $this->input('province_id') : $user->province_id;
            $municipalityId = $this->has('municipality_id') ? $this->input('municipality_id') : $user->municipality_id;
            if ($municipalityId && (!$provinceId
                || (int) Municipality::whereKey($municipalityId)->value('province_id') !== (int) $provinceId)) {
                $validator->errors()->add('municipality_id', 'O município escolhido não pertence a esta província.');
            }
        });
    }

    /** O bairro tem de ser do município da pessoa (o novo, se o estiver a mudar). */
    private function checkNeighborhood(Validator $validator, User $user): void
    {
        $municipalityId = $this->has('municipality_id') ? $this->input('municipality_id') : $user->municipality_id;
        if ($this->filled('neighborhood_id')
            && (int) \App\Models\Neighborhood::whereKey($this->input('neighborhood_id'))->value('municipality_id') !== (int) $municipalityId) {
            $validator->errors()->add('neighborhood_id', 'O bairro escolhido não pertence ao seu município.');
        }
    }

    /** Dados prontos a gravar (telemóvel já normalizado). */
    public function profileData(): array
    {
        $data = collect($this->validated())->except('current_password')->all();

        // Mudou de município sem escolher bairro: o bairro antigo deixa de valer.
        if (array_key_exists('municipality_id', $data) && !array_key_exists('neighborhood_id', $data)
            && (int) $data['municipality_id'] !== (int) $this->user()->municipality_id) {
            $data['neighborhood_id'] = null;
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = app(PhoneNumberService::class)->normalize((string) $data['phone']);
        }

        return $data;
    }
}
