<?php

namespace App\Enums;

enum GasAvailability: string
{
    case AVAILABLE = 'AVAILABLE';
    case UNAVAILABLE = 'UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Gás disponível',
            self::UNAVAILABLE => 'Gás indisponível',
        };
    }
}
