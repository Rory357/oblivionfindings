<?php

namespace App\Domain\Shifts\Timesheets;

use App\Models\Timesheet;

final class TimesheetWorkflowResult
{
    public function __construct(
        public readonly Timesheet $timesheet,
        public readonly bool $changed,
    ) {}
}
