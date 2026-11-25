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
    // Avisos de acesso, consultas grátis e presença (Novembro 2026). Nenhum
    // destes é uma CONSULTA: não gastam consultas grátis (ver consultValues).
    case FREE_CONSULTS_LOW = 'FREE_CONSULTS_LOW';
    case FREE_CONSULTS_EXHAUSTED = 'FREE_CONSULTS_EXHAUSTED';
    case FREE_CONSULTS_RENEWED = 'FREE_CONSULTS_RENEWED';
    case MUNICIPALITY_PAID = 'MUNICIPALITY_PAID';
    case ACCESS_GRANTED = 'ACCESS_GRANTED';
    case ACCESS_REVOKED = 'ACCESS_REVOKED';
    case STATION_OFF_MAP = 'STATION_OFF_MAP';
    case ORDER_TAKEN_BY_OTHER = 'ORDER_TAKEN_BY_OTHER';

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
            self::FREE_CONSULTS_LOW => 'Consultas grátis a acabar',
            self::FREE_CONSULTS_EXHAUSTED => 'Consultas grátis esgotadas',
            self::FREE_CONSULTS_RENEWED => 'Consultas grátis renovadas',
            self::MUNICIPALITY_PAID => 'Município com consulta paga',
            self::ACCESS_GRANTED => 'Acesso à consulta concedido',
            self::ACCESS_REVOKED => 'Acesso à consulta retirado',
            self::STATION_OFF_MAP => 'Posto a sair da aplicação',
            self::ORDER_TAKEN_BY_OTHER => 'Pedido aceite por outro posto',
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
