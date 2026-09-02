<?php

namespace App\Enums;

enum MinuteStatus: string
{
    case Draft = 'draft';
    case Archived = 'archived';
    case Returned = 'returned';
}
