<?php

namespace App\Services\Tasks\Providers;

use App\Models\ShiftTask;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;

class ShiftTaskProvider implements HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'shift_task';
    }

    public function label(): string
    {
        return 'Shift Tasks';
    }

    public function modelClass(): string
    {
        return ShiftTask::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/operations.php: /shifts/{shift} → permission:shifts.viewAny|shifts.viewAssigned.
        return $user->canDo('shifts.viewAny')
            || $user->canDo('shifts.viewAssigned')
            || $user->canDo('shifts.manageAny');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ShiftTask::query()
            ->with([
                'shift:id,user_id,client_id,site_id,starts_at,status',
                'shift.staff:id,name',
                'shift.client:id,first_name,last_name',
                'shift.site:id,name',
            ])
            ->whereHas('shift', function ($q) use ($user) {
                // Today/future plus the recent past week — ancient shift tasks
                // are noise, not actionable work.
                app(UserSiteAccessService::class)->applyShiftScope(
                    $q,
                    $user,
                    ['reports.viewAny'],
                );

                $q->where('starts_at', '>=', now()->subDays(7))
                    ->where('status', '!=', 'cancelled');

                // Schedulers (shifts.manageAny) see every shift's tasks; other
                // staff only the tasks on their OWN shifts.
                if (! $user->canDo('shifts.manageAny')) {
                    $q->where('user_id', $user->id);
                }
            })
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->where('is_completed', false);
        }

        return $query->get()->map(function (ShiftTask $task) {
            $shift = $task->shift;
            $staff = $shift?->staff;
            $client = $shift?->client;

            return new TaskItem(
                id: 'shift_task-'.$task->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: null,
                title: (string) ($task->label ?: 'Shift task'),
                status: $task->is_completed ? 'completed' : 'open',
                bucket: $task->is_completed ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                severity: 'low',
                // The shift's rostered worker owns its tasks.
                assignee: $staff
                    ? ['id' => $staff->id, 'name' => (string) $staff->name]
                    : null,
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                site: $shift?->site
                    ? ['id' => $shift->site->id, 'name' => (string) $shift->site->name]
                    : null,
                // Shift date + scheduled_time when present, else the shift start.
                dueAt: optional($task->scheduledFor() ?? $shift?->starts_at)->toIso8601String(),
                createdAt: optional($task->created_at)->toIso8601String(),
                link: "/shifts/{$task->shift_id}",
                type: 'Shift task',
            );
        })->all();
    }
}
