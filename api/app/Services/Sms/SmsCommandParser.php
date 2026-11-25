<?php

namespace App\Services\Sms;

use App\Enums\GasAvailability;
use App\Enums\QueueLevel;

/**
 * Interpreta o código de 2 dígitos enviado pelos postos.
 *
 * Regra (Master Build Prompt, secção 25):
 *   1º dígito: 0 = sem gás, 1 = com gás
 *   2º dígito: 0 = fila baixa, 1 = fila média, 2 = fila alta
 *
 * Exemplo: "10" = Gás disponível + fila baixa.
 */
class SmsCommandParser
{
    private const QUEUE_MAP = [
        '0' => QueueLevel::LOW,
        '1' => QueueLevel::MEDIUM,
        '2' => QueueLevel::HIGH,
    ];

    /**
     * @return array{gas_available: bool, queue_level: QueueLevel}|null
     */
    public function parseStationCode(string $body): ?array
    {
        $normalized = trim($body);

        if (!preg_match('/^([01])([012])$/', $normalized, $matches)) {
            return null;
        }

        return [
            'gas_available' => $matches[1] === '1',
            'queue_level' => self::QUEUE_MAP[$matches[2]],
        ];
    }

    public function isConsumerQuery(string $body): bool
    {
        return strtoupper(trim($body)) === 'GAS';
    }

    // ── Carregamentos por SMS (Outubro 2026) ───────────────────────────
    // O SMS chega ao telemóvel da central (o fornecedor não recebe SMS): quem
    // atende cola o texto no painel e o servidor interpreta-o aqui.
    //   CARREGAR <quantidade> <número do posto, 5 dígitos> <PIN, 4 a 6 dígitos>
    //   ex.: CARREGAR 10 00023 4821

    /** @return array{quantity: int, station_code: string, pin: string}|null */
    public function parsePickup(string $body): ?array
    {
        $b = preg_replace('/\s+/u', ' ', trim($body));

        if (!preg_match('/^CARREGAR (\d{1,3}) (\d{5}) (\d{4,6})$/iu', (string) $b, $m)) {
            return null;
        }

        return ['quantity' => (int) $m[1], 'station_code' => $m[2], 'pin' => $m[3]];
    }
}
