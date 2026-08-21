<?php

namespace App\Enums;

enum AssuranceStatus: string
{
    case CERTIFIED = 'certified';
    case ACTION_REQUIRED = 'action_required';
    case UNKNOWN = 'unknown';
}
