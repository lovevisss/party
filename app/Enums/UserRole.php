<?php

namespace App\Enums;

enum UserRole: string
{
    case MinuteSubmitter = 'minute_submitter';
    case MinuteManager = 'minute_manager';
    case SystemAdmin = 'system_admin';
}
