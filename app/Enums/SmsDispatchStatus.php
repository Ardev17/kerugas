<?php

namespace App\Enums;

enum SmsDispatchStatus: string
{
    case PENDING = 'PENDING';
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::QUEUED => 'Enfileirado',
            self::SENT => 'Enviado',
            self::DELIVERED => 'Entregue',
            self::FAILED => 'Falhou',
            self::UNKNOWN => 'Desconhecido',
        };
    }
}
