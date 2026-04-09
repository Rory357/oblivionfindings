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
}
