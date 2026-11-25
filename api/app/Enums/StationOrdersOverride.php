<?php

namespace App\Enums;

/** A mão do administrador sobre os pedidos de um posto (Novembro 2026). */
enum StationOrdersOverride: string
{
    /** Decide a subscrição paga. É o valor por omissão. */
    case INHERIT = 'INHERIT';

    /** Recebe pedidos mesmo sem subscrição. */
    case ALLOW = 'ALLOW';

    /** Não recebe, mesmo com subscrição paga. */
    case BLOCK = 'BLOCK';

    public function label(): string
    {
        return match ($this) {
            self::INHERIT => 'Segue a subscrição',
            self::ALLOW => 'Recebe sempre',
            self::BLOCK => 'Nunca recebe',
        };
    }
}
