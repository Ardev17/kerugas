<?php

namespace App\Enums;

enum OperationStatus: string
{
    case DRAFT = 'DRAFT';
    case SENT = 'SENT';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SENT => 'Enviado',
            self::CANCELLED => 'Cancelado',
        };
    }
}
