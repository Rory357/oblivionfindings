<?php

namespace App\Domain\Governance\Console;

use App\Domain\Governance\Services\ComplianceEngineService;
use Illuminate\Console\Command;

/**
 * Daily refresh of each open requirement's stored status from its due date
 * (NZ calendar date): overdue, due in the next 30 days, or not due yet.
 * Pages and reports already work the status out from the due date; this
 * keeps the stored value (reminders, exports, other modules) in step.
 */
class RefreshComplianceStatusesCommand extends Command
{
    protected $signature = 'governance:refresh-compliance-statuses';

    protected $description = 'Update compliance requirement statuses (overdue, due soon, not due) from their due dates';

    public function handle(ComplianceEngineService $compliance): int
    {
        $changed = $compliance->refreshStatuses();

        $this->info(sprintf(
            'Compliance statuses refreshed: %d now overdue, %d due in the next 30 days, %d not due yet.',
            $changed['overdue'],
            $changed['due_soon'],
            $changed['not_due'],
        ));

        return self::SUCCESS;
    }
}
