<?php

namespace App\Services\Tasks\Providers;

use App\Models\ItRecurrencePlan;
use App\Models\ItRecurrenceRun;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Support\Facades\Schema;

/**
 * W20 failure visibility: a recurrence occurrence that failed to create its
 * ticket becomes the plan owner's task until a later occurrence succeeds —
 * a silent scheduler failure never just disappears.
 */
final class ItRecurrenceFailureTaskProvider implements HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'it_recurrence_failure';
    }

    public function label(): string
    {
        return 'Recurring ticket failures';
    }

    public function modelClass(): string
    {
        return ItRecurrencePlan::class;
    }

    public function canView(User $user): bool
    {
        return (bool) $user->canDo('it.manage');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user) || ! Schema::hasTable('it_recurrence_runs')) {
            return [];
        }

        return ItRecurrencePlan::query()
            ->select(['id', 'name', 'owner_user_id', 'status', 'created_at'])
            ->with('owner:id,name')
            ->where('owner_user_id', $user->id)
            ->where('status', '!=', 'retired')
            ->when(isset($filters['id']), fn ($query) => $query->whereKey((int) $filters['id']))
            ->whereHas('runs', fn ($query) => $query->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(14)))
            ->orderBy('id')
            ->get()
            ->map(function (ItRecurrencePlan $plan): ?TaskItem {
                $failed = ItRecurrenceRun::query()->where('plan_id', $plan->id)
                    ->where('status', 'failed')->latest('created_at')->latest('id')->first();
                $recovered = $failed && ItRecurrenceRun::query()->where('plan_id', $plan->id)
                    ->where('status', 'created')
                    ->where('created_at', '>', $failed->created_at)->exists();
                if (! $failed || $recovered) {
                    return null;
                }

                return new TaskItem(
                    id: 'it_recurrence_failure-'.$plan->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: 'REC-'.$plan->id, title: 'Recurring plan "'.$plan->name.'" failed to create its ticket',
                    status: 'run_failed', bucket: TaskItem::BUCKET_OPEN, severity: 'high',
                    assignee: ['id' => (int) $plan->owner_user_id, 'name' => $plan->owner?->name ?? 'Plan owner'],
                    dueAt: $failed->created_at?->toIso8601String(),
                    createdAt: $plan->created_at?->toIso8601String(),
                    link: '/it/setup?tab=automation', type: 'Recurrence failure', restricted: true,
                    actionLabel: 'Review plan', displayState: 'Last occurrence failed',
                    actionHelp: ($failed->detail ? 'Last failure: '.$failed->detail.'. ' : '')
                        .'Fix the plan template (Site, service, priority) or pause the plan; the next successful occurrence clears this.',
                );
            })
            ->filter()
            ->values()
            ->all();
    }
}
