<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\ExpenseJournalService;
use App\Domain\Hr\Models\HrExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostExpenseJournalJob implements ShouldQueue
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
        public readonly HrExpenseClaim $expenseClaim,
    ) {}

    public function handle(ExpenseJournalService $service): void
    {
        try {
            $journal = $service->postExpenseClaimJournal($this->expenseClaim);

            Log::info('Expense claim journal posted successfully.', [
                'expense_claim_id' => $this->expenseClaim->id,
                'claim_number'     => $this->expenseClaim->claim_number,
                'journal_id'       => $journal->id,
                'journal_number'   => $journal->journal_number,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to post expense claim journal.', [
                'expense_claim_id' => $this->expenseClaim->id,
                'claim_number'     => $this->expenseClaim->claim_number,
                'error'            => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
