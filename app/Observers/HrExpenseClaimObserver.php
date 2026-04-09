<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Hr\Models\HrExpenseClaim;
use Illuminate\Support\Facades\Log;

class HrExpenseClaimObserver
{
    /**
     * Dispatch GL posting job when an expense claim is approved.
     *
     * Trigger: HrExpenseClaim::updated (status → approved)
     * GL Entry: DR 6500 Staff Expenses / CR 2310 Expense Claims Payable
     * Payment type: 'reimburse' — staff will be reimbursed
     */
    public function updated(HrExpenseClaim $claim): void
    {
        if (! $claim->wasChanged('status') || $claim->status !== 'approved') {
            return;
        }

        if (! $claim->total_amount || bccomp((string) $claim->total_amount, '0', 2) <= 0) {
            return;
        }

        if ($claim->journal_id) {
            return;
        }

        try {
            $orgId = $claim->tenant_id;
            if (! $orgId) {
                return;
            }

            $accountConfig = config('finance.event_accounts.expense_claim');

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => HrExpenseClaim::class,
                'source_id' => $claim->id,
                'event_type' => 'expense_claim',
                'description' => "Expense claim: {$claim->title} ({$claim->claim_number})",
                'amount' => (string) $claim->total_amount,
                'event_date' => ($claim->approved_at ?? now())->toDateString(),
                'debit_account_code' => $accountConfig['debit'],
                'payment_type' => FinFinancialEvent::PAYMENT_REIMBURSEMENT,
                'journal_type' => $accountConfig['journal_type'],
                'staff_id' => $claim->user_id,
                'source_updated_at' => $claim->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("HrExpenseClaimObserver: Failed to dispatch GL job for claim #{$claim->id}: {$e->getMessage()}");
        }
    }
}
