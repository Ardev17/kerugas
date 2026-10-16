<?php

namespace App\Enums;

enum StationUpdateSource: string
{
    case OFFICIAL_SMS = 'OFFICIAL_SMS';
    case OPERATOR = 'OPERATOR';
    case COMMUNITY = 'COMMUNITY';
    case ADMIN = 'ADMIN';
}
