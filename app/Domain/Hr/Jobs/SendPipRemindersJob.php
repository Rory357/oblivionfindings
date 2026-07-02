<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPipMilestone;
use App\Domain\Hr\Notifications\PipEndingNotification;
use App\Domain\Hr\Notifications\PipMilestoneOverdueNotification;
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
 * All sends are best-effort. Pass a tenant id to scope one tenant.
 */
class SendPipRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACTIVE_STATUSES = ['active', 'in_progress'];

    public function __construct(public ?int $tenantId = null) {}

    public function handle(): void
    {
        $milestoneNudges = $this->remindOverdueMilestones();
        $endNudges = $this->remindEndingPlans();

        Log::info('SendPipRemindersJob: PIP reminder sweep completed.', [
            'tenant_id' => $this->tenantId,
            'milestone_nudges' => $milestoneNudges,
            'end_nudges' => $endNudges,
        ]);
    }

    private function remindOverdueMilestones(): int
    {
        $query = HrPipMilestone::query()
            ->with(['pip.manager:id,name,email', 'pip.employee:id,name,email'])
            ->where('status', 'pending')
            ->whereNull('overdue_reminder_sent_at')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereHas('pip', function ($q) {
                $q->whereIn('status', self::ACTIVE_STATUSES);
                if ($this->tenantId !== null) {
                    $q->where('tenant_id', $this->tenantId);
                }
            });

        $sent = 0;
        $query->chunkById(200, function ($milestones) use (&$sent) {
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

                foreach (collect([$pip->manager, $pip->employee])->filter()->unique('id') as $recipient) {
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

    private function remindEndingPlans(): int
    {
        $query = HrPerformanceImprovementPlan::query()
            ->with(['manager:id,name,email', 'employee:id,name,email'])
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNull('end_reminder_sent_at')
            ->whereDate('end_date', '<=', now()->addDays(7)->toDateString());

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $sent = 0;
        $query->chunkById(200, function ($pips) use (&$sent) {
            foreach ($pips as $pip) {
                if ($pip->manager) {
                    try {
                        $pip->manager->notify(new PipEndingNotification(
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
                }

                $pip->update(['end_reminder_sent_at' => now()]);
            }
        });

        return $sent;
    }
}
