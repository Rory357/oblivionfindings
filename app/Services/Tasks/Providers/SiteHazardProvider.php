<?php

namespace App\Services\Tasks\Providers;

use App\Models\SiteHazard;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class SiteHazardProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'hazard';
    }

    public function label(): string
    {
        return 'Hazards';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = SiteHazard::query()
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            // Mirrors SiteHazard::scopeOpen() — 'mitigated' folds into closed.
            $query->whereIn('status', ['open', 'in_progress']);
        }

        return $query->get()->map(function (SiteHazard $hazard) {
            return new TaskItem(
                id: 'hazard-'.$hazard->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $hazard->reference_number,
                title: $hazard->custom_hazard_type
                    ?: ucfirst(str_replace('_', ' ', (string) $hazard->hazard_type)).' hazard',
                status: (string) $hazard->status,
                bucket: match ($hazard->status) {
                    'open' => TaskItem::BUCKET_OPEN,
                    'in_progress' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_DONE,
                },
                severity: TaskItem::normaliseSeverity($hazard->risk_rating),
                assignee: $hazard->assignedTo
                    ? ['id' => $hazard->assignedTo->id, 'name' => $hazard->assignedTo->name]
                    : null,
                site: $hazard->site
                    ? ['id' => $hazard->site->id, 'name' => $hazard->site->name]
                    : null,
                dueAt: optional($hazard->due_date)->toIso8601String(),
                createdAt: optional($hazard->created_at)->toIso8601String(),
                link: "/hazards/{$hazard->id}",
                type: 'Hazard',
                description: $hazard->description ? str($hazard->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
