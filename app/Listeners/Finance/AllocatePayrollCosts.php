<?php

namespace App\Listeners\Finance;

use App\Domain\Finance\Events\JournalPosted;
use App\Domain\Finance\Jobs\ProcessPayrollAllocationsJob;
use App\Domain\Hr\Models\HrPayrollRun;

/**
 * Listens for JournalPosted events and triggers cost allocation
 * for payroll-type journals.
 *
 * When PayrollJournalService posts a payroll journal, the JournalPosted
 * event fires. This listener checks if the journal is payroll-sourced
 * and dispatches the allocation job.
 *
 * Does NOT modify the journal or payroll run — only creates cost allocations.
 */
class AllocatePayrollCosts
{
    public function handle(JournalPosted $event): void
    {
        $journal = $event->journal;

        // Only process payroll-type journals
        if ($journal->type !== 'payroll') {
            return;
        }

        // Find the payroll run that owns this journal
        $payrollRun = HrPayrollRun::where('journal_id', $journal->id)->first();

        if (! $payrollRun) {
            return;
        }

        // Skip if both wages and on-costs are already allocated
        if ($payrollRun->cost_allocated_at !== null && $payrollRun->oncost_allocated_at !== null) {
            return;
        }

        ProcessPayrollAllocationsJob::dispatch($payrollRun->id);
    }
}
