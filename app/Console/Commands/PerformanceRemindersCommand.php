<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Notifications\FeedbackReminderNotification;
use App\Domain\Hr\Notifications\GoalDueNotification;
use App\Domain\Hr\Notifications\PerformanceReviewDueNotification;
use App\Domain\Hr\Notifications\PipReviewDueNotification;
use App\Domain\Hr\Notifications\SupervisionDueNotification;
use App\Domain\Hr\Services\HrCurrentStaffService;
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

    public function handle(HrCurrentStaffService $currentStaff): int
    {
        $expired = $this->expireOverdueFeedback();
        $reminded = $this->remindDueFeedback();
        $reviewsNudged = $this->remindDueReviews();
        $supNudged = $this->remindOverdueSupervision();
        $pipNudged = $this->remindPipReviews();
        $goalNudged = $this->remindDueGoals($currentStaff);

        $this->info("Performance reminders: {$expired} 360 expired, {$reminded} 360 reminders, {$reviewsNudged} review nudges, {$supNudged} supervision, {$pipNudged} PIP reviews, {$goalNudged} goal due.");

        return self::SUCCESS;
    }

    /** Notify supervisors of acknowledged-pending 1:1s now past their next date. */
    private function remindOverdueSupervision(): int
    {
        $notes = HrSupervisionNote::query()
            ->where('is_visible_to_employee', true)
            ->where('employee_acknowledged', false)
            ->whereNotNull('next_session_date')
            ->whereDate('next_session_date', '<', now())
            ->with('employee:id,name')
            ->get(['id', 'supervisor_user_id', 'employee_user_id', 'next_session_date']);

        $count = 0;
        foreach ($notes as $note) {
            $supervisor = $note->supervisor_user_id ? User::find($note->supervisor_user_id) : null;
            if (! $supervisor) {
                continue;
            }
            $supervisor->notify(new SupervisionDueNotification(
                $note->employee?->name ?? 'an employee',
                $note->next_session_date?->toDateString() ?? now()->toDateString(),
                $note->id,
            ));
            $count++;
        }

        return $count;
    }

    /** Notify PIP owners of plans whose review date falls within 3 days. */
    private function remindPipReviews(): int
    {
        $pips = HrPerformanceImprovementPlan::query()
            ->whereIn('status', ['active', 'in_progress'])
            ->whereNotNull('review_date')
            ->whereBetween('review_date', [now()->startOfDay(), now()->addDays(3)->endOfDay()])
            ->with('employee:id,name')
            ->get(['id', 'manager_user_id', 'employee_user_id', 'review_date', 'status']);

        $count = 0;
        foreach ($pips as $pip) {
            $manager = $pip->manager_user_id ? User::find($pip->manager_user_id) : null;
            if (! $manager) {
                continue;
            }
            $manager->notify(new PipReviewDueNotification(
                $pip->employee?->name ?? 'an employee',
                $pip->review_date?->toDateString() ?? now()->toDateString(),
                $pip->id,
            ));
            $count++;
        }

        return $count;
    }

    /** Nudge goal owners of active goals due within 7 days and not yet complete. */
    private function remindDueGoals(HrCurrentStaffService $currentStaff): int
    {
        $goals = HrGoal::query()
            ->whereIn('user_id', $currentStaff->currentUsersQuery()->select('users.id'))
            ->where('status', 'active')
            ->where('progress_percentage', '<', 100)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->get(['id', 'user_id', 'title', 'due_date']);

        $count = 0;
        foreach ($goals as $goal) {
            $owner = $goal->user_id
                ? $currentStaff->currentUsersQuery()->find($goal->user_id)
                : null;
            if (! $owner) {
                continue;
            }
            $owner->notify(new GoalDueNotification(
                $goal->title,
                $goal->due_date?->toDateString() ?? now()->toDateString(),
                $goal->id,
            ));
            $count++;
        }

        return $count;
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
