<?php

namespace App\Enums;

enum QueueLevel: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Fila baixa',
            self::MEDIUM => 'Fila média',
            self::HIGH => 'Fila alta',
        };
    }
}
