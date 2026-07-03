<?php

namespace App\Services\Tasks;

use App\Models\User;
use App\Services\NotificationService;
use App\Services\Tasks\Contracts\TaskProvider;

/**
 * "You've been assigned CA-2026-0012" — fired when a record is assigned
 * from the /tasks queue. Deliberately thin: module-side assignments keep
 * their own notification paths; this only covers the queue's assign action.
 */
class TaskAssignmentNotifier
{
    public static function notify(User $actor, TaskProvider $provider, int $id, ?int $assigneeId): void
    {
        if ($assigneeId === null || $assigneeId === $actor->id) {
            return; // Unassignment and self-assignment need no ping.
        }

        $item = collect($provider->tasks($actor, ['include_done' => true]))
            ->first(fn (TaskItem $i) => $i->id === $provider->sourceKey()."-{$id}");

        $label = $item?->ref ?? ($item?->title ?? 'a task');

        app(NotificationService::class)->notifyCrud(
            actor: $actor,
            action: 'assigned',
            entityLabel: 'Task',
            entity: null,
            extra: [
                'event_key' => 'tasks.assigned',
                'title' => "You've been assigned {$label}",
                'body' => $item?->title,
                'url' => $item?->link ?? '/tasks?assigned=me',
                'target_user_ids' => [$assigneeId],
            ],
        );
    }
}
