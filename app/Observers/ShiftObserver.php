<?php

namespace App\Observers;

use App\Models\Shift;
use App\Services\ShiftTimelineService;

class ShiftObserver
{
    public function created(Shift $shift): void
    {
        app(ShiftTimelineService::class)->syncSnapshot($shift);
    }

    public function updated(Shift $shift): void
    {
        app(ShiftTimelineService::class)->syncSnapshot($shift);
    }

    public function deleted(Shift $shift): void
    {
        app(ShiftTimelineService::class)->deleteForShift($shift);
    }
}
