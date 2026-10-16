<?php

namespace App\Enums;

enum ReportStatus: string
{
    case OPEN = 'OPEN';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case RESOLVED = 'RESOLVED';
    case REJECTED = 'REJECTED';
}
