<?php

namespace App\Services\Tasks\Providers;

use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

class SafeguardingActionPlanProvider implements TaskProvider, HasModelClass, AssignableTaskProvider
{
    public function sourceKey(): string
    {
        return 'safeguarding_action';
    }

    public function label(): string
    {
        return 'Safeguarding Actions';
    }

    public function modelClass(): string
    {
        return SafeguardingActionPlan::class;
    }

    public function canAssign(User $user): bool
    {
        // SafeguardingActionPlanController authorizes 'update' on the parent
        // concern — globally that is the safeguarding.update permission (the
        // per-record policy branch is re-checked in assign()).
        return $user->canDo('safeguarding.update');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $plan = SafeguardingActionPlan::query()->with('concern')->find($id);
        $concern = $plan?->concern;

        if (! $plan || ! $concern) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Safeguarding action plan not found.',
            ]);
        }

        if ($assigneeId === null) {
            // The module requires every action plan to carry an assignee.
            throw ValidationException::withMessages([
                'assignee_id' => 'Safeguarding action plans must be assigned to a staff member.',
            ]);
        }

        // Mirror of SafeguardingConcernPolicy::update on the parent concern.
        if (! $actor->can('update', $concern)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'You are not authorized to assign this action plan.',
            ]);
        }

        // Same need-to-know rule as the concern itself: restricted viewers of a
        // sensitive concern cannot (re)allocate its action plans.
        $restricted = $concern->is_sensitive
            && ! $actor->can('viewSensitive', SafeguardingConcern::class)
            && $concern->assigned_to_user_id !== $actor->id
            && $concern->reported_by_user_id !== $actor->id;

        if ($restricted) {
            throw ValidationException::withMessages([
                'assignee_id' => 'This concern is restricted — you cannot assign its action plans.',
            ]);
        }

        $plan->update([
            'assigned_to_user_id' => $assigneeId,
            'updated_by' => $actor->id,
        ]);
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/safeguarding.php: the register is gated by safeguarding.viewAny.
        return $user->canDo('safeguarding.viewAny');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = SafeguardingActionPlan::query()
            ->with(['concern:id,reference_number,is_sensitive', 'assignedTo:id,name'])
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('status', ['pending', 'in_progress']);
        }

        return $query->get()->map(function (SafeguardingActionPlan $plan) {
            $concern = $plan->concern;

            // Need-to-know: never surface free-text from a sensitive concern.
            $sensitive = (bool) ($concern?->is_sensitive);

            return new TaskItem(
                id: 'safeguarding_action-'.$plan->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $concern?->reference_number,
                title: $sensitive
                    ? 'Safeguarding action'
                    : str((string) $plan->action_description)->limit(140)->toString(),
                status: (string) $plan->status,
                bucket: match ($plan->status) {
                    'completed', 'cancelled' => TaskItem::BUCKET_DONE,
                    'pending' => TaskItem::BUCKET_OPEN,
                    default => TaskItem::BUCKET_IN_PROGRESS,
                },
                // Priority is an integer column: 1 = high, 2 = medium, 3+ = low.
                severity: match ((int) $plan->priority) {
                    1 => 'high',
                    2 => 'medium',
                    default => 'low',
                },
                assignee: $plan->assignedTo
                    ? ['id' => $plan->assignedTo->id, 'name' => $plan->assignedTo->name]
                    : null,
                dueAt: optional($plan->due_date)->toIso8601String(),
                createdAt: optional($plan->created_at)->toIso8601String(),
                link: "/safeguarding?concern={$plan->safeguarding_concern_id}",
                type: 'Action plan',
                description: null,
            );
        })->all();
    }
}
