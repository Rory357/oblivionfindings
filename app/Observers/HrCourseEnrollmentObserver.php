<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Hr\Models\HrCourseEnrollment;
use Illuminate\Support\Facades\Log;

class HrCourseEnrollmentObserver
{
    /**
     * Dispatch GL posting job when a training enrollment is completed.
     *
     * Trigger: HrCourseEnrollment::updated (status → completed, course.cost > 0)
     * GL Entry: DR 6510 Training / CR 2000 AP (training provider invoice)
     */
    public function updated(HrCourseEnrollment $enrollment): void
    {
        if (! $enrollment->wasChanged('status') || $enrollment->status !== 'completed') {
            return;
        }

        if ($enrollment->journal_id) {
            return;
        }

        try {
            $course = $enrollment->course;
            if (! $course || ! $course->cost || bccomp((string) $course->cost, '0', 2) <= 0) {
                return;
            }

            $orgId = $enrollment->tenant_id;
            if (! $orgId) {
                return;
            }

            // GL double-count rule (handover item 10): the provider-invoice
            // posting (DR 6510 / CR 2000 AP) only belongs to the org-pays-
            // provider model. When the fee is reimbursed to staff via an
            // expense claim, the claim approval books the cost instead, so
            // suppress this posting to avoid booking the same cost twice.
            if ($course->staff_can_claim && ! $course->org_pays_provider) {
                return;
            }

            if ($this->hasLinkedTrainingClaim($enrollment)) {
                return;
            }

            $accountConfig = config('finance.event_accounts.training_cost');

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => HrCourseEnrollment::class,
                'source_id' => $enrollment->id,
                'event_type' => 'training_cost',
                'description' => "Training: {$course->title} ({$course->code})"
                    . ($course->provider ? " — {$course->provider}" : ''),
                'amount' => (string) $course->cost,
                'event_date' => ($enrollment->completed_at ?? now())->toDateString(),
                'debit_account_code' => $accountConfig['debit'],
                'payment_type' => FinFinancialEvent::PAYMENT_AP,
                'journal_type' => $accountConfig['journal_type'],
                'staff_id' => $enrollment->user_id,
                'source_updated_at' => $enrollment->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("HrCourseEnrollmentObserver: Failed to dispatch GL job for enrollment #{$enrollment->id}: {$e->getMessage()}");
        }
    }

    /**
     * Whether a (non-rejected) training expense claim already references this
     * enrollment — i.e. the cost is being reimbursed to staff, so the provider
     * posting must be suppressed.
     */
    private function hasLinkedTrainingClaim(HrCourseEnrollment $enrollment): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('hr_expense_items', 'source_type')) {
            return false;
        }

        return \App\Domain\Hr\Models\HrExpenseItem::query()
            ->where('source_type', HrCourseEnrollment::class)
            ->where('source_id', $enrollment->id)
            ->whereHas('expenseClaim', fn ($q) => $q->where('status', '!=', 'rejected'))
            ->exists();
    }
}
