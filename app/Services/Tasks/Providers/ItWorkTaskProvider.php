<?php

namespace App\Services\Tasks\Providers;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTaskReadinessService;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

/** Read-only projection of canonical IT work into All tasks and My Day. */
final class ItWorkTaskProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'it_work_task';
    }

    public function label(): string
    {
        return 'IT work tasks';
    }

    public function modelClass(): string
    {
        return ItWorkTask::class;
    }

    public function canView(User $user): bool
    {
        return $user->approved_at !== null && $user->canDo('it.manage');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $includeDone = ! empty($filters['include_done']);
        $query = ItWorkTask::query()
            ->with(['ticket.site:id,name', 'assignee:id,name'])
            ->when(isset($filters['id']), fn ($tasks) => $tasks->whereKey((int) $filters['id']))
            ->when(! $includeDone, fn ($tasks) => $tasks->where('status', '!=', 'cancelled'))
            ->orderBy('id');
        $workByTicket = [];
        $readiness = app(ItWorkTaskReadinessService::class);
        $items = app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($tasks, User $actor) => $tasks->whereHas('ticket', function ($tickets) use ($actor, $includeDone): void {
                app(ItWorkAccessService::class)->applyWorkScope($tickets, $actor);
                $tickets->whereNull('merged_into_ticket_id');
                if (! $includeDone) {
                    $tickets->whereIn('status', ItTicket::OPEN_STATUSES);
                }
            }),
            function (ItWorkTask $task) use ($user, $readiness, &$workByTicket): TaskItem {
                $ticket = $task->ticket;
                // One canonical graph per ticket, even when several tasks are assigned.
                $workByTicket[$ticket->id] ??= $readiness->forTicket($ticket, $user);
                $verdict = $workByTicket[$ticket->id]['verdicts'][$task->id];
                $needsReview = $task->status === 'completed' && $verdict['completion'] === 'invalid';
                $done = in_array($task->status, ['completed', 'cancelled'], true) && ! $needsReview;
                $blocked = $verdict['prerequisites'] === 'blocked';
                $state = $needsReview ? 'Completion needs review' : ($blocked && ! $done ? 'Waiting for prerequisites' : match ($task->status) {
                    'pending' => 'Ready to start', 'in_progress' => 'In progress', 'blocked' => 'Blocked',
                    'completed' => 'Completed', 'cancelled' => 'Cancelled', default => 'Review task',
                });
                if (! $done && ! $verdict['storage_ready']) {
                    $state = 'Task history setup incomplete';
                } elseif (! $done && ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
                    $state = 'Ticket settled — review outstanding work';
                }

                return new TaskItem(
                    id: 'it_work_task-'.$task->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $ticket->reference,
                    title: $task->title,
                    status: $task->status,
                    bucket: $done ? TaskItem::BUCKET_DONE : ($task->status === 'in_progress' && ! $blocked ? TaskItem::BUCKET_IN_PROGRESS : TaskItem::BUCKET_OPEN),
                    severity: match ($ticket->priority) {
                        'urgent' => 'critical', 'high' => 'high', 'normal' => 'medium', default => 'low',
                    },
                    assignee: $task->assignee ? ['id' => (int) $task->assignee->id, 'name' => $task->assignee->name] : null,
                    site: $ticket->site ? ['id' => (int) $ticket->site->id, 'name' => $ticket->site->name] : null,
                    dueAt: $task->due_at?->toIso8601String(),
                    createdAt: $task->created_at?->toIso8601String(),
                    link: '/it/tickets/'.$ticket->id.'?tab=tasks#task-'.$task->id,
                    type: $task->is_required ? 'Required IT task' : 'Optional IT task',
                    restricted: true,
                    sourceContext: $ticket->title,
                    actionLabel: 'Open IT work task',
                    displayState: $state,
                    actionHelp: $verdict['blockers'][0]['message'] ?? $verdict['warnings'][0]['message'] ?? null,
                );
            },
        );

        // Completed tasks with invalid evidence remain actionable. No new
        // persisted task, assignment endpoint or private evidence copy is created.
        return $includeDone ? $items : array_values(array_filter($items, fn (TaskItem $item): bool => $item->bucket !== TaskItem::BUCKET_DONE));
    }
}
