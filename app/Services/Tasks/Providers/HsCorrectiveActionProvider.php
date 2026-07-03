<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsCorrectiveAction;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

class HsCorrectiveActionProvider implements TaskProvider, HasModelClass, AssignableTaskProvider
{
    public function sourceKey(): string
    {
        return 'corrective_action';
    }

    public function label(): string
    {
        return 'Corrective Actions';
    }

    public function modelClass(): string
    {
        return HsCorrectiveAction::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/health-safety.php: all corrective-action writes sit
        // behind permission:hazards.manage.
        return $user->canDo('hazards.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $action = HsCorrectiveAction::query()->find($id);

        if (! $action) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Corrective action not found.',
            ]);
        }

        if ($action->status === HsCorrectiveAction::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'assignee_id' => 'A closed corrective action cannot be reassigned.',
            ]);
        }

        // Same side-effect columns HsCorrectiveActionService stamps on
        // create/start: assignee + who assigned it + when.
        $action->update([
            'assigned_to_user_id' => $assigneeId,
            'assigned_by_user_id' => $assigneeId !== null ? $actor->id : null,
            'assigned_at' => $assigneeId !== null ? now() : null,
            'updated_by' => $actor->id, // module service stamps this on every write
        ]);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = HsCorrectiveAction::query()
            ->with(['assignedTo:id,name'])
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsCorrectiveAction::STATUS_CLOSED);
        }

        return $query->get()->map(function (HsCorrectiveAction $action) {
            return new TaskItem(
                id: 'corrective_action-'.$action->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $action->reference_number,
                title: $action->title ?: 'Corrective action',
                status: (string) $action->status,
                bucket: match ($action->status) {
                    HsCorrectiveAction::STATUS_CLOSED => TaskItem::BUCKET_DONE,
                    HsCorrectiveAction::STATUS_IN_PROGRESS,
                    HsCorrectiveAction::STATUS_COMPLETED,
                    HsCorrectiveAction::STATUS_VERIFIED => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($action->priority),
                assignee: $action->assignedTo
                    ? ['id' => $action->assignedTo->id, 'name' => $action->assignedTo->name]
                    : null,
                dueAt: optional($action->due_date)->toIso8601String(),
                createdAt: optional($action->created_at)->toIso8601String(),
                link: $action->reference_number
                    ? '/health-safety/corrective-actions?q='.rawurlencode($action->reference_number)
                    : "/health-safety/events/{$action->hs_event_id}",
                type: 'Corrective action',
                description: $action->description ? str($action->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
