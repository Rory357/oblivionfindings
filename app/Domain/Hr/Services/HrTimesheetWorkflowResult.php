<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrTimesheet;

final class HrTimesheetWorkflowResult
{
    public function __construct(
        public readonly HrTimesheet $timesheet,
        public readonly bool $changed,
    ) {}
}
