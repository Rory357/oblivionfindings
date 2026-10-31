<?php

namespace App\Jobs;

use App\Models\HsRiskAssessment;
use App\Services\HealthSafety\HsSignalService;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Detect risk assessments past their review due date.
 *
 * Runs daily. Finds active or under-review assessments before the NZ calendar day.
 */
class CheckRiskAssessmentReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(HsSignalService $signalService, UserSiteAccessService $siteAccess): void
    {
        $query = HsRiskAssessment::query()->overdueForReview();
        $siteAccess->applyHsRiskAssessmentApplicationScope($query);

        $overdueAssessments = $query
            ->with(['hsEvent:id,site_id', 'assessable'])
            ->get();

        $count = 0;

        foreach ($overdueAssessments as $assessment) {
            $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
            $due = Carbon::parse($assessment->review_due_at->toDateString(), config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
            $daysOverdue = (int) $due->diffInDays($today);
            $siteId = $siteAccess->effectiveHsRiskAssessmentSiteId($assessment);

            $signalService->emitRiskReviewOverdue(
                $assessment->id,
                $assessment->reference_number ?? "RA-{$assessment->id}",
                $daysOverdue,
                $assessment->risk_level ?? 'medium',
                $siteId,
                [
                    'risk_score' => $assessment->risk_score,
                    'residual_risk_level' => $assessment->residual_risk_level,
                    'review_due_at' => $assessment->review_due_at->toDateString(),
                    'assessable_type' => $assessment->assessable_type,
                    'assessable_id' => $assessment->assessable_id,
                    'hs_event_id' => $assessment->hs_event_id,
                    'title' => $assessment->title,
                ],
            );

            $count++;
        }

        if ($count > 0) {
            Log::info('CheckRiskAssessmentReviewsJob: completed', ['overdue_count' => $count]);
        }
    }
}
