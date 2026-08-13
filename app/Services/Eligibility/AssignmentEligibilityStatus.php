<?php

namespace App\Services\Eligibility;

enum AssignmentEligibilityStatus: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case HardBlock = 'hard_block';
    case Unavailable = 'unavailable';
}
