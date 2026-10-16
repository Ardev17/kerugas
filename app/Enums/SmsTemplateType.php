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
    // Subscrições mensais (Outubro 2026).
    case SUBSCRIPTION_REQUIRED = 'SUBSCRIPTION_REQUIRED';
    case SUBSCRIPTION_EXPIRED = 'SUBSCRIPTION_EXPIRED';
    case SUBSCRIPTION_ACTIVATED = 'SUBSCRIPTION_ACTIVATED';
    case OPERATOR_ANALYTICS = 'OPERATOR_ANALYTICS';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Geral',
            self::AVAILABILITY => 'Disponibilidade de gás',
            self::NO_GAS => 'Sem gás disponível',
            self::DELIVERY_REQUEST => 'Pedido de entrega',
            self::STATION_INFO => 'Informação de posto',
            self::NOT_REGISTERED => 'Cliente não registado',
            self::SUBSCRIPTION_REQUIRED => 'Subscrição necessária',
            self::SUBSCRIPTION_EXPIRED => 'Subscrição terminada',
            self::SUBSCRIPTION_ACTIVATED => 'Subscrição ativada',
            self::OPERATOR_ANALYTICS => 'Aviso ao operador (analytics)',
        };
    }

    /**
     * Tipos que entregam uma CONSULTA de gás ao cliente — são os que gastam
     * uma das consultas grátis do mês e os únicos bloqueados sem subscrição.
     *
     * @return string[]
     */
    public static function consultValues(): array
    {
        return [self::AVAILABILITY->value, self::NO_GAS->value, self::STATION_INFO->value];
    }

    public function isConsult(): bool
    {
        return in_array($this->value, self::consultValues(), true);
    }
}
