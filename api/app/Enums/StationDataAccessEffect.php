<?php

namespace App\Enums;

/** Excepção por pessoa à regra do município (Novembro 2026). */
enum StationDataAccessEffect: string
{
    /** Consulta mesmo que o município esteja fechado. */
    case ALLOW = 'ALLOW';

    /** Não consulta, mesmo que o município esteja aberto. */
    case DENY = 'DENY';
}
