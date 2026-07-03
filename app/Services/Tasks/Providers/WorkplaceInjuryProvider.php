<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class WorkplaceInjuryProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'injury';
    }

    public function label(): string
    {
        return 'Workplace Injuries';
    }

    public function modelClass(): string
    {
        return WorkplaceInjury::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/health-safety.php: permission:hazards.view|hr.wellbeing.view
        return $user->canDo('hazards.view') || $user->canDo('hr.wellbeing.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = WorkplaceInjury::query()
            ->with(['user:id,name', 'site:id,name'])
            ->orderByDesc('injury_date')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['recovered', 'closed']);
        }

        return $query->get()->map(function (WorkplaceInjury $injury) {
            return new TaskItem(
                id: 'injury-'.$injury->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $injury->reference_number,
                title: 'Injury — '.($injury->user?->name ?: 'Unknown worker'),
                status: (string) $injury->status,
                bucket: match ($injury->status) {
                    'recovered', 'closed' => TaskItem::BUCKET_DONE,
                    'under_treatment', 'return_to_work' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                // Injury vocab is minor/moderate/serious/critical — 'serious' is not in
                // the shared map, so promote it to 'high' explicitly.
                severity: $injury->severity === 'serious'
                    ? 'high'
                    : TaskItem::normaliseSeverity($injury->severity),
                assignee: null,
                site: $injury->site
                    ? ['id' => $injury->site->id, 'name' => $injury->site->name]
                    : null,
                dueAt: null,
                createdAt: optional($injury->created_at)->toIso8601String(),
                link: "/health-safety/injuries?injury={$injury->id}",
                type: 'Injury',
                description: $injury->description ? str($injury->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
