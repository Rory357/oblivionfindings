<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrKeyResult;
use App\Domain\Hr\Notifications\DevelopmentReviewDueNotification;
use App\Domain\Hr\Notifications\GoalCheckinDueNotification;
use App\Domain\Hr\Notifications\GoalOverdueNotification;
use App\Domain\Hr\Notifications\KeyResultDueNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily OKR & development reminders: check-in due (by cadence), overdue
 * objectives, key results due within 3 days, and development-plan reviews.
 *
 * Check-in/overdue/KR nags recur daily until resolved (the daily schedule
 * bounds them to one per item per day). Development reviews are idempotent: the
 * plan's next_review_at is bumped forward by the cadence once the reminder fires.
 */
class SendGoalRemindersCommand extends Command
{
    protected $signature = 'hr:goal-reminders';

    protected $description = 'Send OKR check-in/overdue/KR-due reminders and development-plan review reminders.';

    public function handle(): int
    {
        $today = Carbon::today();
        $cadence = [
            'weekly' => 7,
            'fortnightly' => 14,
            'monthly' => 30,
            'quarterly' => 90,
        ];

        $sent = 0;

        // --- Objectives: check-in due + overdue ---
        HrGoal::query()
            ->where('status', 'active')
            ->with('user:id,name')
            ->chunkById(200, function ($goals) use (&$sent, $today, $cadence) {
                foreach ($goals as $goal) {
                    $owner = $goal->user;
                    if (! $owner) {
                        continue;
                    }

                    // Check-in due
                    $days = $cadence[$goal->checkin_frequency] ?? 14;
                    $last = $goal->last_checkin_at;
                    if ($last === null || $last->lt($today->copy()->subDays($days))) {
                        $owner->notify(new GoalCheckinDueNotification($goal));
                        $sent++;
                    }

                    // Overdue
                    if ($goal->due_date && $goal->due_date->lt($today)) {
                        $owner->notify(new GoalOverdueNotification($goal));
                        $sent++;
                    }
                }
            });

        // --- Key results due within 3 days, not complete ---
        HrKeyResult::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->addDays(3))
            ->with(['owner:id,name', 'goal:id,user_id,status'])
            ->chunkById(200, function ($krs) use (&$sent) {
                foreach ($krs as $kr) {
                    if (! $kr->goal || $kr->goal->status !== 'active') {
                        continue;
                    }
                    $recipientId = $kr->owner_id ?? $kr->goal->user_id;
                    $recipient = $kr->owner ?? User::find($recipientId);
                    if ($recipient) {
                        $recipient->notify(new KeyResultDueNotification($kr));
                        $sent++;
                    }
                }
            });

        // --- Development plan reviews due ---
        HrDevelopmentGoal::query()
            ->whereIn('status', ['not_started', 'in_progress', 'blocked'])
            ->whereNotNull('review_frequency')
            ->where(function ($q) use ($today) {
                $q->whereNull('next_review_at')->orWhereDate('next_review_at', '<=', $today);
            })
            ->with(['employee:id,name', 'manager:id,name'])
            ->chunkById(200, function ($plans) use (&$sent, $today) {
                foreach ($plans as $plan) {
                    // First-ever reminder seeds next_review_at from due_date/today.
                    if ($plan->next_review_at === null) {
                        $base = $plan->due_date ?? $today;
                        if ($base->gt($today)) {
                            $plan->forceFill(['next_review_at' => $base])->saveQuietly();

                            continue;
                        }
                    }

                    foreach (array_filter([$plan->employee, $plan->manager]) as $person) {
                        $person->notify(new DevelopmentReviewDueNotification($plan));
                        $sent++;
                    }

                    // Bump forward so the nag is idempotent until the next cycle.
                    $next = $plan->nextReviewFrom($today) ?? $today->copy()->addDays(30);
                    $plan->forceFill(['next_review_at' => $next])->saveQuietly();
                }
            });

        $this->info("Goal reminders dispatched: {$sent}.");

        return self::SUCCESS;
    }
}
