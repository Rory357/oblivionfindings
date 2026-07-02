<?php

namespace App\Services\Tasks\Providers;

use App\Models\SafeguardingActionPlan;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class SafeguardingActionPlanProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'safeguarding_action';
    }

    public function label(): string
    {
        return 'Safeguarding Actions';
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
