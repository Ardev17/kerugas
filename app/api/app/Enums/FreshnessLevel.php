<?php

namespace App\Enums;

enum FreshnessLevel: string
{
    case FRESH = 'FRESH';       // 0-15 min
    case RECENT = 'RECENT';     // 15-60 min
    case STALE = 'STALE';       // 1-3h
    case EXPIRED = 'EXPIRED';   // 3h+

    public function label(): string
    {
        return match ($this) {
            self::FRESH => 'Muito recente',
            self::RECENT => 'Recente',
            self::STALE => 'Atenção — desatualizando',
            self::EXPIRED => 'Informação desatualizada',
        };
    }
}
