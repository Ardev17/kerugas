<?php

namespace App\Services\Sms;

/**
 * Contagem tecnicamente correta de caracteres e segmentos SMS.
 *
 * Distingue GSM-7 (160 caracteres por SMS único, 153 por segmento em
 * mensagens concatenadas, com caracteres de extensão a contar como 2)
 * de Unicode/UCS-2 (70 caracteres por SMS único, 67 por segmento),
 * necessário sempre que a mensagem tiver acentos fora do alfabeto
 * GSM-7 básico/estendido (secção 24 do master prompt).
 */
class SmsSegmentCounter
{
    // Strings de um só byte por letra (single-quoted) para evitar que o
    // lexer do PHP tente interpretar "$" seguido de um byte estendido
    // (ex: "$¥") como início de interpolação de variável — o que não é
    // permitido dentro de uma expressão de constante de classe.
    private const GSM7_BASIC = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    private const GSM7_EXTENDED = '^{}\\[~]|€';

    private const GSM7_SINGLE_LIMIT = 160;
    private const GSM7_MULTIPART_LIMIT = 153;
    private const UCS2_SINGLE_LIMIT = 70;
    private const UCS2_MULTIPART_LIMIT = 67;

    /**
     * @return array{encoding: 'GSM-7'|'UCS-2', length: int, segments: int, limit_per_segment: int}
     */
    public function count(string $message): array
    {
        $isGsm7 = $this->isGsm7Compatible($message);

        $length = $isGsm7 ? $this->gsm7Length($message) : mb_strlen($message, 'UTF-8');

        $singleLimit = $isGsm7 ? self::GSM7_SINGLE_LIMIT : self::UCS2_SINGLE_LIMIT;
        $multipartLimit = $isGsm7 ? self::GSM7_MULTIPART_LIMIT : self::UCS2_MULTIPART_LIMIT;

        $segments = $length <= $singleLimit
            ? ($length === 0 ? 0 : 1)
            : (int) ceil($length / $multipartLimit);

        return [
            'encoding' => $isGsm7 ? 'GSM-7' : 'UCS-2',
            'length' => $length,
            'segments' => $segments,
            'limit_per_segment' => $segments > 1 ? $multipartLimit : $singleLimit,
        ];
    }

    private function isGsm7Compatible(string $message): bool
    {
        $allowed = self::GSM7_BASIC . self::GSM7_EXTENDED;

        $chars = mb_str_split($message, 1, 'UTF-8');

        foreach ($chars as $char) {
            if (!str_contains($allowed, $char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cada caracter de extensão GSM-7 (ex: €, [, ], {, }) ocupa 2
     * posições porque exige um caracter de escape.
     */
    private function gsm7Length(string $message): int
    {
        $length = 0;

        foreach (mb_str_split($message, 1, 'UTF-8') as $char) {
            $length += str_contains(self::GSM7_EXTENDED, $char) ? 2 : 1;
        }

        return $length;
    }
}
