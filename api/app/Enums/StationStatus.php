<?php

namespace App\Enums;

enum StationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case PENDING = 'PENDING';
    case SUSPENDED = 'SUSPENDED';
}
