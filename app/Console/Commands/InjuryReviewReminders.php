<?php

namespace App\Console\Commands;

use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Notifications\HealthSafety\InjuryReviewDueNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Injuries & RTW — Step 8 (cross-module seam 7).
 *
 * Runs daily: finds active return-to-work plans whose next review is due/overdue
 * and capacity assessments whose next reassessment is due (both on still-open
 * injuries), then delivers a digest notification to each responsible manager
 * (in-app bell). Uses the model scopes that previously had zero callers.
 */
class InjuryReviewReminders extends Command
{
    protected $signature = 'injuries:review-reminders';

    protected $description = 'Surface return-to-work plan reviews and capacity reassessments that are due on open injuries.';

    /** Statuses where the injury is resolved and no longer needs review nags. */
    private const RESOLVED = ['closed', 'recovered'];

    public function handle(): int
    {
        $openInjury = fn (Builder $q) => $q->whereHas('workplaceInjury', fn (Builder $w) => $w->whereNotIn('status', self::RESOLVED));

        $rtwPlans = ReturnToWorkPlan::query()
            ->whereNotNull('next_review_date')
            ->whereDate('next_review_date', '<=', now())
            ->where('status', 'active')
            ->where($openInjury)
            ->get(['id', 'manager_id', 'created_by']);

        $capAssessments = WorkCapacityAssessment::query()
            ->whereNotNull('next_assessment_date')
            ->whereDate('next_assessment_date', '<=', now())
            ->where($openInjury)
            ->get(['id', 'created_by', 'user_id']);

        // Tally outstanding items per responsible manager → one digest each.
        $perLead = []; // user_id => ['rtw' => int, 'capacity' => int]
        foreach ($rtwPlans as $plan) {
            $lead = $plan->manager_id ?? $plan->created_by;
            if ($lead) {
                $perLead[$lead]['rtw'] = ($perLead[$lead]['rtw'] ?? 0) + 1;
            }
        }
        foreach ($capAssessments as $a) {
            $lead = $a->created_by ?? $a->user_id;
            if ($lead) {
                $perLead[$lead]['capacity'] = ($perLead[$lead]['capacity'] ?? 0) + 1;
            }
        }

        $notified = 0;
        if ($perLead !== []) {
            foreach (User::query()->whereIn('id', array_keys($perLead))->get() as $lead) {
                $counts = $perLead[$lead->id];
                $lead->notify(new InjuryReviewDueNotification(
                    $counts['rtw'] ?? 0,
                    $counts['capacity'] ?? 0,
                ));
                $notified++;
            }
        }

        $this->info("Injury reminders: {$rtwPlans->count()} RTW review(s) due, {$capAssessments->count()} capacity reassessment(s) due; notified {$notified} manager(s).");

        Log::info('injuries.review_reminders', [
            'rtw_reviews_due' => $rtwPlans->count(),
            'capacity_followups_due' => $capAssessments->count(),
            'leads_notified' => $notified,
        ]);

        return self::SUCCESS;
    }
}
