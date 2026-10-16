<?php

namespace App\Enums;

enum SubscriptionType: string
{
    case SMS_CONSULT = 'SMS_CONSULT';
    case STATION_ANALYTICS = 'STATION_ANALYTICS';

    public function label(): string
    {
        return match ($this) {
            self::SMS_CONSULT => 'Consulta de gás por SMS',
            self::STATION_ANALYTICS => 'Analytics do posto',
        };
    }
}
