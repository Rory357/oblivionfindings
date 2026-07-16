<?php

namespace App\Services\Tasks\Providers;

use App\Domain\Governance\Models\ActionItem;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

class ActionItemProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'action_item';
    }

    public function label(): string
    {
        return 'Governance Actions';
    }

    public function modelClass(): string
    {
        return ActionItem::class;
    }

    public function canAssign(User $user): bool
    {
        // ActionItemPolicy::update — the module's write-side gate for changing
        // an existing action item (the assignee branch only covers lifecycle
        // verbs on your own item, not reallocating it).
        return $user->canDo('governance.actions.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $action = ActionItem::query()->find($id);

        if (! $action) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Governance action not found.',
            ]);
        }

        if ($assigneeId === null) {
            // StoreActionItemRequest requires assigned_to — items are never ownerless.
            throw ValidationException::withMessages([
                'assignee_id' => 'Governance actions must be assigned to a staff member.',
            ]);
        }

        if ($action->status === 'complete') {
            throw ValidationException::withMessages([
                'assignee_id' => 'A completed action item cannot be reassigned.',
            ]);
        }

        $action->update(['assigned_to' => $assigneeId]);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('governance.actions.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ActionItem::query()
            ->with('assignedTo:id,name')
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
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
