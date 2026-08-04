<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Notifications\EngagementActionPlanDueNotification;
use App\Domain\Hr\Services\HrWellbeingAccessService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEngagementActionPlanRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $access = app(HrWellbeingAccessService::class);
        $beforeDays = collect(config('hr.engagement.action_plan_reminder_days_before', [14, 7, 3, 1, 0]))
            ->filter(fn ($day) => is_numeric($day))
            ->map(fn ($day) => (int) $day)
            ->unique()
            ->sort()
            ->values();

        $overdueDays = collect(config('hr.engagement.action_plan_overdue_escalation_days', [1, 3, 7]))
            ->filter(fn ($day) => is_numeric($day))
            ->map(fn ($day) => (int) $day)
            ->unique()
            ->sort()
            ->values();

        $maxBefore = (int) ($beforeDays->max() ?? 0);
        $maxOverdue = (int) ($overdueDays->max() ?? 0);

        $sentCount = 0;

        HrEngagementActionPlan::query()
            ->with(['owner:id,name,email', 'staff:id,name', 'survey:id,title,audience_type,audience_site_ids'])
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->subDays($maxOverdue)->toDateString(), now()->addDays($maxBefore)->toDateString()])
            ->chunkById(200, function ($plans) use ($access, $beforeDays, $overdueDays, &$sentCount) {
                foreach ($plans as $plan) {
                    $dueDate = $plan->due_date;
                    if (! $dueDate) {
                        continue;
                    }

                    $daysUntilDue = now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false);
                    $reminderKind = $daysUntilDue < 0 ? 'overdue' : 'upcoming';
                    $triggered = $daysUntilDue < 0
                        ? $overdueDays->contains(abs($daysUntilDue))
                        : $beforeDays->contains($daysUntilDue);

                    if (! $triggered) {
                        continue;
                    }

                    $recipientIds = collect([$plan->owner_user_id, $plan->created_by])
                        ->filter(fn ($id) => is_numeric($id))
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values();

                    if ($recipientIds->isEmpty()) {
                        continue;
                    }

                    User::query()
                        ->whereIn('id', $recipientIds->all())
                        ->chunkById(100, function ($users) use ($access, $plan, $daysUntilDue, $reminderKind, &$sentCount) {
                            foreach ($users as $user) {
                                if (! $access->canAccessActionPlan($user, $plan)) {
                                    continue;
                                }

                                $alreadySent = $user->notifications()
                                    ->where('type', EngagementActionPlanDueNotification::class)
                                    ->where('data->action_plan_id', $plan->id)
                                    ->where('data->due_date', optional($plan->due_date)->toDateString())
                                    ->where('data->days_until_due', $daysUntilDue)
                                    ->where('data->reminder_kind', $reminderKind)
                                    ->exists();

                                if ($alreadySent) {
                                    continue;
                                }

                                try {
                                    $user->notify(new EngagementActionPlanDueNotification($plan, $daysUntilDue, $reminderKind));
                                    $sentCount++;
                                } catch (\Throwable $exception) {
                                    Log::warning('Failed to send engagement action plan reminder', [
                                        'plan_id' => $plan->id,
                                        'user_id' => $user->id,
                                        'error' => $exception->getMessage(),
                                    ]);
                                }
                            }
                        });
                }
            });

        Log::info('SendEngagementActionPlanRemindersJob completed.', [
            'sent' => $sentCount,
            'before_days' => $beforeDays->all(),
            'overdue_days' => $overdueDays->all(),
        ]);
    }
}
