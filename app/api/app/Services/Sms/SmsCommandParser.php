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
}
