<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejeita quebras de linha e outros caracteres de controlo (Novembro 2026).
 *
 * Porquê: a CVE-2026-48019 mostrou que a regra `email` por omissão do
 * Laravel aceita sequências CR/LF que, combinadas com a forma como o
 * Symfony Mailer e o Symfony Mime montam a mensagem, permitem injectar
 * cabeçalhos e fazer SMTP smuggling. O Laravel 11 está em fim de vida
 * desde 12/03/2026 e não recebe a correcção do upstream, por isso
 * bloqueamos o vector à entrada.
 *
 * Aplica-se a qualquer campo cujo valor possa acabar num cabeçalho de
 * email: endereços, nomes e assuntos.
 */
class SemCaracteresDeControlo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // o tipo é tratado por outras regras
        }

        // UTF-8 inválido impede a análise fiável do conteúdo: recusa-se.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $fail('O campo :attribute contém caracteres inválidos.');

            return;
        }

        // C0 (U+0000–U+001F), DEL (U+007F) e C1 (U+0080–U+009F).
        // Inclui CR, LF e NUL, que são o que permite partir um cabeçalho.
        if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $value) === 1) {
            $fail('O campo :attribute não pode conter quebras de linha nem caracteres invisíveis.');
        }
    }
}
