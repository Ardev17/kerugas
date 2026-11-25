<?php

namespace App\Http\Requests\Profile;

use App\Services\Media\AvatarService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Primeira barreira do upload da foto de perfil. `mimes` aqui é avaliado
 * pelo Laravel a partir do CONTEÚDO do ficheiro (finfo), não da extensão;
 * o AvatarService volta a verificar tudo e re-codifica a imagem.
 */
class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'max:' . AvatarService::MAX_KB, 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'Escolha uma foto.',
            'avatar.file' => 'Não foi possível receber a imagem. Tente de novo.',
            'avatar.uploaded' => 'Não foi possível receber a imagem (pode ser maior do que o servidor aceita).',
            'avatar.max' => 'A imagem tem de ter no máximo 5 MB.',
            'avatar.mimes' => 'Formato não suportado. Use uma foto JPG, PNG ou WebP.',
        ];
    }
}
