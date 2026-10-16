<?php

namespace App\Enums;

enum SmsTemplateType: string
{
    case GENERAL = 'GENERAL';
    case AVAILABILITY = 'AVAILABILITY';
    case NO_GAS = 'NO_GAS';
    case DELIVERY_REQUEST = 'DELIVERY_REQUEST';
    case STATION_INFO = 'STATION_INFO';
    case NOT_REGISTERED = 'NOT_REGISTERED';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Geral',
            self::AVAILABILITY => 'Disponibilidade de gás',
            self::NO_GAS => 'Sem gás disponível',
            self::DELIVERY_REQUEST => 'Pedido de entrega',
            self::STATION_INFO => 'Informação de posto',
            self::NOT_REGISTERED => 'Cliente não registado',
        };
    }
}
