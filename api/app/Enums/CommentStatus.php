<?php

namespace App\Enums;

enum CommentStatus: string
{
    case VISIBLE = 'VISIBLE';
    case PENDING = 'PENDING';
    case HIDDEN = 'HIDDEN';
    case DELETED = 'DELETED';
}
