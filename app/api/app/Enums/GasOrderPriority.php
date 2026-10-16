<?php

namespace App\Enums;

enum GasOrderPriority: string
{
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';
}
