<?php

namespace App\Enums;

enum GasOrderStatus: string
{
    case PENDING = 'PENDING';
    case RECEIVED = 'RECEIVED';
    case CONTACTING = 'CONTACTING';
    case CONFIRMED = 'CONFIRMED';
    case ASSIGNED = 'ASSIGNED';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERED = 'DELIVERED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::RECEIVED => 'Recebido',
            self::CONTACTING => 'Em contacto',
            self::CONFIRMED => 'Confirmado',
            self::ASSIGNED => 'Atribuído',
            self::OUT_FOR_DELIVERY => 'Em entrega',
            self::DELIVERED => 'Entregue',
            self::CANCELLED => 'Cancelado',
            self::FAILED => 'Falhou',
        };
    }
}
