<?php

namespace App\Enums;

enum LocationSource: string
{
    case GPS = 'GPS';
    case MANUAL = 'MANUAL';
}
