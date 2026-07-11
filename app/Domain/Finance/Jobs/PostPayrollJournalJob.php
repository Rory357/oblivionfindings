<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\PayrollJournalService;
use App\Domain\Hr\Models\HrPayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostPayrollJournalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 1;

    public function __construct(
        public readonly HrPayrollRun $payrollRun,
    ) {}

    public function handle(PayrollJournalService $service): void
    {
        $payrollRun = $this->payrollRun->fresh();

        if (! $payrollRun) {
            Log::warning('Skipping payroll journal posting because the payroll run no longer exists.', [
                'payroll_run_id' => $this->payrollRun->id,
            ]);

            return;
        }

        if ($payrollRun->locked_at === null) {
            Log::warning('Skipping payroll journal posting because the payroll run is not locked.', [
                'payroll_run_id' => $payrollRun->id,
            ]);

            return;
        }

        if ($payrollRun->journal_id !== null) {
            Log::info('Skipping payroll journal posting because the payroll run is already linked to a journal.', [
                'payroll_run_id' => $payrollRun->id,
                'journal_id' => $payrollRun->journal_id,
            ]);

            return;
        }

        try {
            $journal = $service->postPayrollJournal($payrollRun);

            // Clear any earlier failure now that the post succeeded.
            $payrollRun->update(['gl_error' => null]);

            Log::info('Payroll journal posted successfully.', [
                'payroll_run_id' => $payrollRun->id,
                'journal_id' => $journal->id,
                'journal_number' => $journal->journal_number,
            ]);
        } catch (\Throwable $e) {
            // Surface the failure on the run itself ($tries = 1, so a throw
            // otherwise only reaches failed_jobs — invisible to payroll users).
            $payrollRun->update(['gl_error' => $e->getMessage()]);

            Log::error('Failed to post payroll journal.', [
                'payroll_run_id' => $payrollRun->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
