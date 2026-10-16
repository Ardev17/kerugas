<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Confirmação da palavra-passe atual em ações sensíveis (Outubro 2026),
 * com limite de tentativas POR CONTA: quem apanhe uma sessão aberta não
 * pode adivinhar a palavra-passe (5 erros = espera de 15 minutos).
 */
final class PasswordConfirmation
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    /** @return string|null mensagem de erro, ou null se confirmada */
    public static function check(User $user, ?string $password): ?string
    {
        $key = 'pwd-confirm:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return "Demasiadas tentativas com a palavra-passe errada. Tente de novo dentro de {$minutes} minuto(s).";
        }

        if (!Hash::check((string) $password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return null === $password || $password === ''
                ? 'Confirme com a sua palavra-passe atual.'
                : 'A palavra-passe atual está errada.';
        }

        RateLimiter::clear($key);

        return null;
    }
}
