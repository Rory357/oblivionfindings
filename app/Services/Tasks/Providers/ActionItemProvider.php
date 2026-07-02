<?php

namespace App\Services\Tasks\Providers;

use App\Domain\Governance\Models\ActionItem;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class ActionItemProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'action_item';
    }

    public function label(): string
    {
        return 'Governance Actions';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('governance.actions.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ActionItem::query()
            ->with('assignedTo:id,name')
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('status', ['open', 'in_progress', 'blocked']);
        }

        return $query->get()->map(function (ActionItem $action) {
            return new TaskItem(
                id: 'action_item-'.$action->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $action->action_reference,
                title: str((string) $action->description)->limit(140)->toString(),
                status: (string) $action->status,
                bucket: match ($action->status) {
                    'complete' => TaskItem::BUCKET_DONE,
                    'in_progress', 'blocked' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($action->priority),
                assignee: $action->assignedTo
                    ? ['id' => $action->assignedTo->id, 'name' => (string) $action->assignedTo->name]
                    : null,
                dueAt: optional($action->due_date)->toIso8601String(),
                createdAt: optional($action->created_at)->toIso8601String(),
                link: '/governance/actions',
                type: 'Action item',
            );
        })->all();
    }
}
