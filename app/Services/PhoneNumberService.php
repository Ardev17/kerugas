<?php

namespace App\Services;

/**
 * Normaliza números de telefone angolanos para um formato canônico único,
 * evitando contas/registos duplicados por variação do mesmo número.
 *
 * Formato canônico adotado: +244XXXXXXXXX (sem espaços, sem zero inicial).
 */
class PhoneNumberService
{
    public function normalize(string $rawPhone): ?string
    {
        $digits = preg_replace('/\D+/', '', $rawPhone);

        if ($digits === null || $digits === '') {
            return null;
        }

        // Remove prefixo internacional 00
        if (str_starts_with($digits, '00244')) {
            $digits = substr($digits, 2);
        }

        // 244XXXXXXXXX (12 dígitos)
        if (str_starts_with($digits, '244') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        // 0XXXXXXXXX (10 dígitos, com zero inicial local)
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+244' . substr($digits, 1);
        }

        // 9XXXXXXXX (9 dígitos, formato local sem zero)
        if (str_starts_with($digits, '9') && strlen($digits) === 9) {
            return '+244' . $digits;
        }

        // Formato não reconhecido — devolver null para forçar validação a montante
        return null;
    }

    public function isValid(string $rawPhone): bool
    {
        return $this->normalize($rawPhone) !== null;
    }
}
