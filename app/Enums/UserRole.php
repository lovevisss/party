<?php

namespace App\Enums;

enum UserRole: string
{
    case CollegeSubmitter = 'college_submitter';
    case SchoolManager = 'school_manager';
    case SystemAdmin = 'system_admin';
}
