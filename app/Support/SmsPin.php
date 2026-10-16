<?php

namespace App\Support;

/** Regras do PIN de SMS do condutor (Outubro 2026): 4 a 6 números, nunca fácil de adivinhar, nunca igual à palavra-passe. */
final class SmsPin
{
    private const WEAK = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321', '1212', '2580', '123456', '654321', '000000', '111111', '121212', '112233'];

    public static function isWeak(string $pin): bool
    {
        return in_array($pin, self::WEAK, true);
    }

    /** Devolve a mensagem de erro, ou null se o PIN serve. */
    public static function problem(string $pin, ?string $password = null): ?string
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return 'O PIN tem de ter 4 a 6 números.';
        }
        if (self::isWeak($pin)) {
            return 'Esse PIN é fácil de adivinhar. Escolha outro.';
        }
        if ($password !== null && $pin === $password) {
            return 'O PIN de SMS tem de ser diferente da palavra-passe da conta.';
        }

        return null;
    }
}
