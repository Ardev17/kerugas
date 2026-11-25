<?php

namespace App\Enums;

/**
 * A mão do administrador sobre a PRESENÇA de um posto na aplicação
 * (Novembro 2026). Só conta quando `listing_enforcement` estiver ligada.
 */
enum StationListingOverride: string
{
    /** Decide a subscrição de presença. Valor por omissão. */
    case INHERIT = 'INHERIT';

    /** Aparece sempre, mesmo sem pagar. */
    case ALLOW = 'ALLOW';

    /** Não aparece, mesmo tendo pago. */
    case BLOCK = 'BLOCK';

    public function label(): string
    {
        return match ($this) {
            self::INHERIT => 'Segue a subscrição',
            self::ALLOW => 'Aparece sempre',
            self::BLOCK => 'Não aparece',
        };
    }
}
