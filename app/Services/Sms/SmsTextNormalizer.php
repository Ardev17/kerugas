<?php

namespace App\Services\Sms;

/**
 * Converte texto para o alfabeto SMS básico (GSM-7) antes de enviar.
 *
 * PORQUÊ (Setembro 2026, custo real): o GSM-7 NÃO tem á, ã, â, ç, ê, í,
 * ó, ô, õ, ú (só é, à, ü e mais alguns). Uma única letra dessas — basta o
 * "Á" de "KERUGÁS" ou o "á" de "gás" — faz a operadora codificar a
 * mensagem INTEIRA em UCS-2, cujo limite é 70 caracteres por SMS em vez
 * de 160. Um SMS de 110 caracteres passava a ser cobrado como 2.
 * Transliterar (á→a, ç→c...) mantém a mensagem perfeitamente legível e
 * volta a caber num só SMS. Emojis são removidos pelo mesmo motivo.
 *
 * Usado em todos os pontos que GERAM o texto do SMS (pré-visualização e
 * envio da Central de Operações, alertas, campanhas), para o que o admin
 * vê na pré-visualização ser exatamente o que é enviado e cobrado.
 */
class SmsTextNormalizer
{
    /** Carateres fora do GSM-7 → equivalente mais próximo dentro dele. */
    private const MAP = [
        'á' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'À' => 'A',
        'ç' => 'c',
        'ê' => 'e', 'ë' => 'e', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
        'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Î' => 'I', 'Ì' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O',
        'ú' => 'u', 'û' => 'u', 'Ú' => 'U', 'Û' => 'U', 'Ù' => 'U',
        'º' => 'o', 'ª' => 'a', '·' => '-', '•' => '-',
        '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...',
        "\u{00A0}" => ' ',
    ];

    public function toGsm7(string $text): string
    {
        $text = strtr($text, self::MAP);

        // Emojis e símbolos que não existem em GSM-7 saem (em vez de
        // forçarem a mensagem toda para UCS-2).
        $text = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text);

        // Espaços duplicados que a remoção de emojis possa ter deixado.
        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
