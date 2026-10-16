<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Velocidade (Outubro 2026): o Sanctum grava `last_used_at` em CADA pedido
 * autenticado — com o sininho, o painel do condutor e a central a
 * atualizarem de 20 em 20 / 30 em 30 segundos, era um UPDATE à base de
 * dados por pedido, por pessoa ligada. Agora só grava quando o valor
 * guardado tem mais de 5 minutos: "Sessões ativas" no perfil e a campanha
 * "Sentimos a sua falta" (45 dias) continuam certas, com muito menos
 * escrita.
 *
 * Continua a ser um PersonalAccessToken do Sanctum (os `instanceof` do
 * AuthController/ProfileController funcionam igual). Registado no
 * AppServiceProvider com Sanctum::usePersonalAccessTokenModel().
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public const LAST_USED_RESOLUTION_SECONDS = 300;

    protected $table = 'personal_access_tokens';

    public function save(array $options = []): bool
    {
        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            $previous = $this->getOriginal('last_used_at');
            if ($previous !== null
                && Carbon::parse($previous)->gt(now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS))) {
                // Recente o suficiente: não escreve, e o modelo fica "limpo".
                $this->syncOriginalAttribute('last_used_at');
                return true;
            }
        }

        return parent::save($options);
    }
}
