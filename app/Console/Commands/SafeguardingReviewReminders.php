<?php

namespace App\Console\Commands;

use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safeguarding redesign — Step 7b (W9).
 *
 * Surfaces the monitoring worklist on a schedule: open concerns whose risk
 * review is due/overdue, and external-report acknowledgements awaited beyond a
 * threshold. Logs a summary so it can drive notifications/dashboards.
 */
class SafeguardingReviewReminders extends Command
{
    protected $signature = 'safeguarding:review-reminders {--days=7 : Acknowledgement-overdue threshold in days}';

    protected $description = 'Surface safeguarding risk reviews due and external-report acknowledgements awaited.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        $reviewsDue = SafeguardingConcern::query()
            ->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES)
            ->whereHas('riskAssessments', fn ($q) => $q
                ->whereNotNull('next_review_date')
                ->where('next_review_date', '<=', now()))
            ->count();

        $acksAwaited = SafeguardingExternalReport::query()
            ->where('acknowledgement_received', false)
            ->where('reported_at', '<=', now()->subDays($days))
            ->whereHas('concern', fn ($q) => $q->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES))
            ->count();

        $this->info("Safeguarding reminders: {$reviewsDue} risk review(s) due, {$acksAwaited} acknowledgement(s) awaited (>{$days}d).");

        Log::info('safeguarding.review_reminders', [
            'reviews_due' => $reviewsDue,
            'acks_awaited' => $acksAwaited,
            'ack_threshold_days' => $days,
        ]);

        return self::SUCCESS;
    }
}
