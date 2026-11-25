<?php

namespace App\Enums;

enum SubscriptionType: string
{
    case SMS_CONSULT = 'SMS_CONSULT';
    /**
     * DESCONTINUADO em Novembro 2026: o analytics passou a vir incluído na
     * subscrição de presença (STATION_LISTING). O caso fica para os registos
     * antigos continuarem a ler, mas já não se vendem subscrições deste tipo.
     */
    case STATION_ANALYTICS = 'STATION_ANALYTICS';
    // Novembro 2026: o posto paga por semana para receber os pedidos do seu município.
    case STATION_ORDERS = 'STATION_ORDERS';

    /**
     * Presença do posto na aplicação, por semana. Pensada para uma fase bem
     * mais à frente: só conta quando a definição `listing_enforcement` for
     * ligada, e até lá todos os postos aparecem sem pagar nada.
     */
    case STATION_LISTING = 'STATION_LISTING';

    public function label(): string
    {
        return match ($this) {
            self::SMS_CONSULT => 'Consulta de gás por SMS',
            self::STATION_ANALYTICS => 'Analytics do posto (descontinuado)',
            self::STATION_ORDERS => 'Pedidos do município (posto)',
            self::STATION_LISTING => 'Presença do posto na aplicação',
        };
    }

    /** Subscrições de um POSTO (as que o Super Admin Provincial também gere). */
    public function isStation(): bool
    {
        return $this !== self::SMS_CONSULT;
    }

    /** Tipos que ainda se podem vender hoje. */
    public static function sellableValues(): array
    {
        return [self::SMS_CONSULT->value, self::STATION_ORDERS->value, self::STATION_LISTING->value];
    }

    /** Tipos cobrados por SEMANA, em blocos. */
    public function isWeekly(): bool
    {
        return $this === self::STATION_ORDERS || $this === self::STATION_LISTING;
    }

    /** @return list<string> */
    public static function stationValues(): array
    {
        return [self::STATION_ANALYTICS->value, self::STATION_ORDERS->value, self::STATION_LISTING->value];
    }
}
