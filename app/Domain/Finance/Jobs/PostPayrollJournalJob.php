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
    public int $tries = 3;

    /**
     * Seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    public function __construct(
        public readonly HrPayrollRun $payrollRun,
    ) {}

    public function handle(PayrollJournalService $service): void
    {
        try {
            $journal = $service->postPayrollJournal($this->payrollRun);

            Log::info('Payroll journal posted successfully.', [
                'payroll_run_id' => $this->payrollRun->id,
                'journal_id'    => $journal->id,
                'journal_number' => $journal->journal_number,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to post payroll journal.', [
                'payroll_run_id' => $this->payrollRun->id,
                'error'          => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
