<?php

namespace App\Enums;

/** Resultado da decisão sobre consultar o estado de um posto. */
enum StationDataAccessStatus: string
{
    case ALLOWED = 'ALLOWED';

    /** O município está fechado e não sabemos quem está do outro lado. */
    case NEEDS_LOGIN = 'NEEDS_LOGIN';

    case BLOCKED = 'BLOCKED';
}
