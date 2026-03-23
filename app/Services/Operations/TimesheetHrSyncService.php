<?php

namespace App\Services\Operations;

use App\Models\Timesheet;

class TimesheetHrSyncService
{
    public function syncToHr(Timesheet $timesheet): void
    {
        // Phase 4: On timesheet approval, create/update corresponding HR time entry
        // Maps shift hours to payroll categories (standard, overtime, sleepover, etc.)
        // Updates exported_to_payroll_at field
    }

    public function mapPayType(Timesheet $timesheet): string
    {
        // Determine pay type based on shift time, day of week, etc.
        return 'standard';
    }
}
