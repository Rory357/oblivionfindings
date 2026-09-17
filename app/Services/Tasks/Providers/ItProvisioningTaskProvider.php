<?php

namespace App\Services\Tasks\Providers;

use App\Domain\It\Services\ItProvisioningAccessService;
use App\Domain\It\Services\ItProvisioningReadinessService;
use App\Models\ItProvisioningRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Support\Carbon;

/** Read-only Tasks/My Day projection of the original provisioning work. */
final class ItProvisioningTaskProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'it_provisioning';
    }

    public function label(): string
    {
        return 'IT provisioning';
    }

    public function modelClass(): string
    {
        return ItProvisioningRequest::class;
    }

    public function canView(User $user): bool
    {
        return $user->approved_at !== null && $user->canDo('it.manage');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $access = app(ItProvisioningAccessService::class);
        $readiness = app(ItProvisioningReadinessService::class);

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user),
            ItProvisioningRequest::query()->with(['employeeProfile.user:id,name', 'workflow', 'assignee:id,name'])
                ->when(isset($filters['id']), fn ($query) => $query->whereKey((int) $filters['id']))
                ->when(empty($filters['include_done']), fn ($query) => $query->whereNotIn('status', ['done', 'cancelled']))->orderBy('id'),
            fn ($query, User $actor) => $access->applyRequestScope($query, $actor),
            function (ItProvisioningRequest $task) use ($user, $access, $readiness): TaskItem {
                $verdict = $readiness->forRequest($task, $user);
                $site = Site::query()->find($access->siteIdFor($task), ['id', 'name']);
                $done = in_array($task->status, ['done', 'cancelled'], true);

                return new TaskItem(id: 'it_provisioning-'.$task->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: 'IT-P'.str_pad((string) $task->id, 6, '0', STR_PAD_LEFT), title: $task->item, status: $task->status,
                    bucket: $done ? TaskItem::BUCKET_DONE : ($task->status === 'in_progress' && $verdict['blockers'] === [] ? TaskItem::BUCKET_IN_PROGRESS : TaskItem::BUCKET_OPEN),
                    severity: match ($task->priority) {
                        'urgent' => 'critical', 'high' => 'high', 'normal' => 'medium', default => 'low'
                    },
                    assignee: $verdict['approver'] ?? $verdict['worker'], site: $site ? ['id' => (int) $site->id, 'name' => $site->name] : null,
                    dueAt: $task->due_date ? Carbon::createFromFormat('!Y-m-d', $task->due_date->toDateString(), config('app.worker_timezone', 'Pacific/Auckland'))->endOfDay()->toIso8601String() : null,
                    createdAt: $task->created_at?->toIso8601String(),
                    link: '/it/provisioning/tasks/'.$task->id, type: $task->workflow ? ucfirst($task->workflow->lifecycle_type).' work' : 'Provisioning task',
                    restricted: true, sourceContext: $task->employeeProfile?->user?->name ?? 'Employee provisioning',
                    actionLabel: 'Open provisioning task', displayState: $verdict['next_action'], actionHelp: $verdict['blockers'][0] ?? null);
            });
    }
}
