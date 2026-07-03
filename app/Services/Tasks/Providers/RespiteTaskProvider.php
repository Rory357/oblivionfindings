<?php

namespace App\Services\Tasks\Providers;

use App\Models\RespiteTask;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class RespiteTaskProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'respite_task';
    }

    public function label(): string
    {
        return 'Respite Tasks';
    }

    public function modelClass(): string
    {
        return RespiteTask::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/respite.php: /respite/tasks → permission:respite.tasks.view.
        return $user->canDo('respite.tasks.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = RespiteTask::query()
            ->with('assignedTo:id,name')
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('status', [
                RespiteTask::STATUS_PENDING,
                RespiteTask::STATUS_IN_PROGRESS,
                RespiteTask::STATUS_AWAITING_APPROVAL,
                RespiteTask::STATUS_BLOCKED,
            ]);
        }

        return $query->get()->map(function (RespiteTask $task) {
            return new TaskItem(
                id: 'respite_task-'.$task->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: null,
                title: (string) ($task->title ?: 'Respite task'),
                status: (string) $task->status,
                bucket: match ($task->status) {
                    RespiteTask::STATUS_COMPLETED,
                    RespiteTask::STATUS_APPROVED,
                    RespiteTask::STATUS_SKIPPED => TaskItem::BUCKET_DONE,
                    RespiteTask::STATUS_IN_PROGRESS,
                    RespiteTask::STATUS_AWAITING_APPROVAL,
                    RespiteTask::STATUS_BLOCKED => TaskItem::BUCKET_IN_PROGRESS,
                    // pending | rejected (rejected needs rework)
                    default => TaskItem::BUCKET_OPEN,
                },
                // Priority is the string vocabulary low/medium/high/critical.
                severity: TaskItem::normaliseSeverity($task->priority),
                assignee: $task->assignedTo
                    ? ['id' => $task->assignedTo->id, 'name' => (string) $task->assignedTo->name]
                    : null,
                dueAt: optional($task->due_at)->toIso8601String(),
                createdAt: optional($task->created_at)->toIso8601String(),
                link: "/respite/tasks/{$task->id}",
                type: 'Respite task',
                description: $task->description ? str($task->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
