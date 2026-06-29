<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Notifications\FeedbackReminderNotification;
use App\Domain\Hr\Notifications\PerformanceReviewDueNotification;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Performance & Development hub — daily reminder + escalation sweep.
 *
 * 1. Auto-expires 360 feedback requests left pending past their due date.
 * 2. Reminds the reviewer of each still-pending 360 request due within 3 days.
 * 3. Nudges reviewers of performance reviews coming due within 7 days.
 *
 * All notifications are in-app (database channel). Wire from routes/console.php.
 */
class PerformanceRemindersCommand extends Command
{
    protected $signature = 'hr:performance-reminders';

    protected $description = 'Expire overdue 360 requests and remind reviewers of due 360s and performance reviews.';

    public function handle(): int
    {
        $expired = $this->expireOverdueFeedback();
        $reminded = $this->remindDueFeedback();
        $reviewsNudged = $this->remindDueReviews();

        $this->info("Performance reminders: {$expired} 360 expired, {$reminded} 360 reminders, {$reviewsNudged} review nudges.");

        return self::SUCCESS;
    }

    /** Pending 360 requests past their due date become `expired`. */
    private function expireOverdueFeedback(): int
    {
        return HrFeedbackRequest::query()
            ->where('status', 'pending')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->update(['status' => 'expired']);
    }

    /** Remind reviewers of pending 360 requests due within the next 3 days. */
    private function remindDueFeedback(): int
    {
        $due = HrFeedbackRequest::query()
            ->where('status', 'pending')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(3)->endOfDay()])
            ->with(['reviewer', 'subject:id,name'])
            ->get();

        $count = 0;
        foreach ($due as $request) {
            if (! $request->reviewer) {
                continue;
            }
            $request->reviewer->notify(new FeedbackReminderNotification($request, $request->subject?->name ?? 'a colleague'));
            $count++;
        }

        return $count;
    }

    /** Nudge reviewers of reviews coming due within 7 days (not yet finalised). */
    private function remindDueReviews(): int
    {
        $reviews = HrPerformanceReview::query()
            ->whereNotIn('status', ['completed', 'signed_off'])
            ->whereNotNull('next_review_date')
            ->whereBetween('next_review_date', [now()->subDays(30)->startOfDay(), now()->addDays(7)->endOfDay()])
            ->with('employee:id,name')
            ->get(['id', 'reviewer_user_id', 'employee_user_id', 'review_type', 'next_review_date', 'status']);

        $count = 0;
        foreach ($reviews as $review) {
            $reviewer = $review->reviewer_user_id ? User::find($review->reviewer_user_id) : null;
            if (! $reviewer) {
                continue;
            }
            $reviewer->notify(new PerformanceReviewDueNotification(
                $review->review_type ?? 'review',
                $review->employee?->name ?? 'an employee',
                $review->next_review_date?->toDateString() ?? now()->toDateString(),
                $review->id,
            ));
            $count++;
        }

        return $count;
    }
}
