<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Notifications\GoalWeeklyDigestNotification;
use App\Domain\Hr\Services\HrCurrentStaffService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Weekly OKR digest to every objective owner: their portfolio's confidence
 * split, overdue count, check-ins due and average progress.
 */
class SendGoalWeeklyDigestCommand extends Command
{
    protected $signature = 'hr:goal-weekly-digest';

    protected $description = 'Send each objective owner a weekly OKR digest.';

    public function handle(HrCurrentStaffService $currentStaff): int
    {
        $today = Carbon::today();
        $cadence = [
            'weekly' => 7,
            'fortnightly' => 14,
            'monthly' => 30,
            'quarterly' => 90,
        ];

        $goals = HrGoal::query()
            ->whereIn('user_id', $currentStaff->currentUsersQuery()->select('users.id'))
            ->where('status', 'active')
            ->get();
        $byOwner = $goals->groupBy('user_id');

        $sent = 0;

        foreach ($byOwner as $ownerId => $owned) {
            $owner = $currentStaff->currentUsersQuery()->find($ownerId);
            if (! $owner) {
                continue;
            }

            $checkinsDue = $owned->filter(function (HrGoal $g) use ($today, $cadence) {
                $days = $cadence[$g->checkin_frequency] ?? 14;

                return $g->last_checkin_at === null || $g->last_checkin_at->lt($today->copy()->subDays($days));
            })->count();

            $stats = [
                'on_track' => $owned->where('confidence', 'on_track')->count(),
                'at_risk' => $owned->where('confidence', 'at_risk')->count(),
                'off_track' => $owned->where('confidence', 'off_track')->count(),
                'overdue' => $owned->filter(fn (HrGoal $g) => $g->due_date && $g->due_date->lt($today))->count(),
                'checkins_due' => $checkinsDue,
                'avg_progress' => (int) round($owned->avg('progress_percentage') ?? 0),
            ];

            $owner->notify(new GoalWeeklyDigestNotification($stats));
            $sent++;
        }

        $this->info("Weekly OKR digests sent: {$sent}.");

        return self::SUCCESS;
    }
}
