<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case AVAILABILITY = 'availability';
    case QUEUE = 'queue';
    case FAVORITE = 'favorite';
    case SYSTEM = 'system';
    case SECURITY = 'security';
    case ORDER = 'order';
}
