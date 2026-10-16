<?php

namespace App\Enums;

enum SmsMessageStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PROCESSED = 'PROCESSED';
    case INVALID = 'INVALID';
    case FAILED = 'FAILED';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case DUPLICATE = 'DUPLICATE';
}
