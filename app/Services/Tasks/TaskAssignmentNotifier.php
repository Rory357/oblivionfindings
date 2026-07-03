<?php

namespace App\Services\Tasks;

use App\Models\TaskWatcher;
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

        $notifications = app(NotificationService::class);

        $notifications->notifyCrud(
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
                // Personal ping only — without these, NotificationService's
                // defaults fan the "You've been assigned…" message out to
                // every manager-role user.
                'include_managers' => false,
                'include_assigned_workers' => false,
                'include_entity_user' => false,
            ],
        );

        // FYI the item's watchers of the reassignment — everyone "Following"
        // except the actor and the new assignee (who got the ping above). One
        // fan-out call for the whole watcher set.
        $watcherIds = TaskWatcher::query()
            ->where('source', $provider->sourceKey())
            ->where('item_id', $id)
            ->whereNotIn('user_id', array_filter([$actor->id, $assigneeId]))
            ->pluck('user_id')
            ->all();

        if ($watcherIds !== []) {
            $notifications->notifyCrud(
                actor: $actor,
                action: 'assigned',
                entityLabel: 'Task',
                entity: null,
                extra: [
                    'event_key' => 'tasks.assigned',
                    'title' => "{$label} was reassigned",
                    'body' => $item?->title,
                    'url' => $item?->link ?? '/tasks',
                    'target_user_ids' => $watcherIds,
                    'include_managers' => false,
                    'include_assigned_workers' => false,
                    'include_entity_user' => false,
                ],
            );
        }
    }
}
