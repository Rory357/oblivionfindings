<?php

namespace App\Domain\Shifts\Lifecycle;

enum ShiftLifecycleSource: string
{
    case Manual = 'manual';
    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';
    case Bulk = 'bulk';
    case AutoExpiry = 'auto_expiry';
}
