<?php

namespace App\Console\Commands;

use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\User;
use App\Notifications\Safeguarding\SafeguardingReviewDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safeguarding redesign — Step 7b (W9).
 *
 * Runs daily: finds open concerns whose risk review is due/overdue and
 * external-report acknowledgements awaited beyond a threshold, then delivers a
 * digest notification to each assigned lead (in-app bell) and logs a summary.
 */
class SafeguardingReviewReminders extends Command
{
    protected $signature = 'safeguarding:review-reminders {--days=7 : Acknowledgement-overdue threshold in days}';

    protected $description = 'Surface safeguarding risk reviews due and external-report acknowledgements awaited.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        // Open concerns with a due/overdue risk review.
        $reviewConcerns = SafeguardingConcern::query()
            ->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES)
            ->whereHas('riskAssessments', fn ($q) => $q
                ->whereNotNull('next_review_date')
                ->where('next_review_date', '<=', now()))
            ->get(['id', 'assigned_to_user_id']);

        // External reports awaiting acknowledgement beyond the threshold (open concern).
        $ackReports = SafeguardingExternalReport::query()
            ->where('acknowledgement_received', false)
            ->where('reported_at', '<=', now()->subDays($days))
            ->whereHas('concern', fn ($q) => $q->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES))
            ->with('concern:id,assigned_to_user_id')
            ->get();

        $reviewsDue = $reviewConcerns->count();
        $acksAwaited = $ackReports->count();

        // Tally each lead's outstanding items so we can send one digest per lead.
        $perLead = []; // user_id => ['reviews' => int, 'acks' => int]
        foreach ($reviewConcerns as $concern) {
            if ($concern->assigned_to_user_id) {
                $perLead[$concern->assigned_to_user_id]['reviews'] = ($perLead[$concern->assigned_to_user_id]['reviews'] ?? 0) + 1;
            }
        }
        foreach ($ackReports as $report) {
            $lead = $report->concern?->assigned_to_user_id;
            if ($lead) {
                $perLead[$lead]['acks'] = ($perLead[$lead]['acks'] ?? 0) + 1;
            }
        }

        $notified = 0;
        if ($perLead !== []) {
            foreach (User::query()->whereIn('id', array_keys($perLead))->get() as $lead) {
                $counts = $perLead[$lead->id];
                $lead->notify(new SafeguardingReviewDueNotification(
                    $counts['reviews'] ?? 0,
                    $counts['acks'] ?? 0,
                ));
                $notified++;
            }
        }

        $this->info("Safeguarding reminders: {$reviewsDue} risk review(s) due, {$acksAwaited} acknowledgement(s) awaited (>{$days}d); notified {$notified} lead(s).");

        Log::info('safeguarding.review_reminders', [
            'reviews_due' => $reviewsDue,
            'acks_awaited' => $acksAwaited,
            'ack_threshold_days' => $days,
            'leads_notified' => $notified,
        ]);

        return self::SUCCESS;
    }
}
