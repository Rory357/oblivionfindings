<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPipMilestone;
use App\Domain\Hr\Notifications\PipEndingNotification;
use App\Domain\Hr\Notifications\PipMilestoneOverdueNotification;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily PIP lifecycle sweep (scheduled in routes/console.php):
 *
 * - Milestones past their due date and still pending → in-app nudge to the
 *   PIP manager and the subject employee (one-time via
 *   `overdue_reminder_sent_at`).
 * - Active plans ending within 7 days (or already ended) with no outcome
 *   recorded → in-app nudge to the PIP manager (one-time via
 *   `end_reminder_sent_at`).
 *
 * The sweep runs once for the application. Current-staff eligibility and the
 * manager's canonical Site visibility are revalidated immediately before each
 * reminder; historical storage markers are never an execution boundary.
 */
class SendPipRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACTIVE_STATUSES = ['active', 'in_progress'];

    public function handle(
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
    ): void {
        $currentUserIds = $currentStaff->currentUserIds();
        $milestoneNudges = $this->remindOverdueMilestones(
            $currentUserIds,
            $currentStaff,
            $siteAccess,
        );
        $endNudges = $this->remindEndingPlans(
            $currentUserIds,
            $currentStaff,
            $siteAccess,
        );

        Log::info('SendPipRemindersJob: PIP reminder sweep completed.', [
            'scope' => 'application',
            'milestone_nudges' => $milestoneNudges,
            'end_nudges' => $endNudges,
        ]);
    }

    /** @param list<int> $currentUserIds */
    private function remindOverdueMilestones(
        array $currentUserIds,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
    ): int {
        $query = HrPipMilestone::query()
            ->with(['pip.manager:id,name,email', 'pip.employee:id,name,email'])
            ->where('status', 'pending')
            ->whereNull('overdue_reminder_sent_at')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereHas('pip', fn ($pip) => $pip
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereIn('employee_user_id', $currentUserIds));

        $sent = 0;
        $query->chunkById(200, function ($milestones) use (&$sent, $currentStaff, $siteAccess) {
            foreach ($milestones as $milestone) {
                $pip = $milestone->pip;
                if (! $pip) {
                    continue;
                }

                $notification = new PipMilestoneOverdueNotification(
                    $pip->id,
                    $pip->title,
                    $milestone->title,
                    $milestone->due_date?->toDateString() ?? now()->toDateString(),
                    $pip->employee?->name ?? 'an employee',
                );

                $employee = $pip->employee && $currentStaff->isCurrent($pip->employee)
                    ? $pip->employee
                    : null;
                $manager = $this->eligibleManager($pip, $currentStaff, $siteAccess);
                $recipients = collect([$employee, $manager])->filter()->unique('id');
                if ($recipients->isEmpty()) {
                    continue;
                }

                foreach ($recipients as $recipient) {
                    try {
                        $recipient->notify($notification);
                        $sent++;
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }

                $milestone->update(['overdue_reminder_sent_at' => now()]);
            }
        });

        return $sent;
    }

    /** @param list<int> $currentUserIds */
    private function remindEndingPlans(
        array $currentUserIds,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
    ): int {
        $query = HrPerformanceImprovementPlan::query()
            ->with(['manager:id,name,email', 'employee:id,name,email'])
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereIn('employee_user_id', $currentUserIds)
            ->whereNull('end_reminder_sent_at')
            ->whereDate('end_date', '<=', now()->addDays(7)->toDateString());

        $sent = 0;
        $query->chunkById(200, function ($pips) use (&$sent, $currentStaff, $siteAccess) {
            foreach ($pips as $pip) {
                $manager = $this->eligibleManager($pip, $currentStaff, $siteAccess);
                if (! $manager) {
                    continue;
                }

                try {
                    $manager->notify(new PipEndingNotification(
                        $pip->id,
                        $pip->title,
                        $pip->end_date?->toDateString() ?? now()->toDateString(),
                        $pip->employee?->name ?? 'an employee',
                        (bool) $pip->end_date?->isPast(),
                    ));
                    $sent++;
                } catch (\Throwable $exception) {
                    report($exception);
                }

                $pip->update(['end_reminder_sent_at' => now()]);
            }
        });

        return $sent;
    }

    private function eligibleManager(
        HrPerformanceImprovementPlan $pip,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
    ): ?User {
        $manager = $pip->manager;
        if (! $manager || ! $currentStaff->isCurrent($manager)) {
            return null;
        }

        $canSeeSubject = $siteAccess->applyStaffScope(
            User::query(),
            $manager,
            ['hr.performance.manage'],
        )->whereKey($pip->employee_user_id)->exists();

        return $canSeeSubject ? $manager : null;
    }
}
