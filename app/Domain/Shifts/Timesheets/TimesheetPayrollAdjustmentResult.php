<?php

namespace App\Domain\Shifts\Timesheets;

use App\Models\TimesheetAmendment;

/** Exact current command result; an external marker is not a settlement. */
final class TimesheetPayrollAdjustmentResult
{
    public function __construct(
        public readonly TimesheetAmendment $amendment,
        public readonly int $actorId,
        public readonly bool $changed,
        public readonly ?string $error = null,
    ) {}
}
