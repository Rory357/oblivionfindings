<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class HsInvestigationProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'hs_investigation';
    }

    public function label(): string
    {
        return 'H&S Investigations';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = HsInvestigation::query()
            ->with(['leadInvestigator:id,name'])
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', [HsInvestigation::STATUS_COMPLETED]);
        }

        return $query->get()->map(function (HsInvestigation $investigation) {
            return new TaskItem(
                id: 'hs_investigation-'.$investigation->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $investigation->reference_number,
                title: ucfirst(str_replace('_', ' ', (string) $investigation->investigation_type)).' investigation',
                status: (string) $investigation->status,
                bucket: match ($investigation->status) {
                    HsInvestigation::STATUS_DRAFT => TaskItem::BUCKET_OPEN,
                    HsInvestigation::STATUS_COMPLETED => TaskItem::BUCKET_DONE,
                    default => TaskItem::BUCKET_IN_PROGRESS,
                },
                severity: 'medium',
                assignee: $investigation->leadInvestigator
                    ? ['id' => $investigation->leadInvestigator->id, 'name' => $investigation->leadInvestigator->name]
                    : null,
                dueAt: optional($investigation->target_completion_date)->toIso8601String(),
                createdAt: optional($investigation->created_at)->toIso8601String(),
                link: "/health-safety/events/{$investigation->hs_event_id}",
                type: 'Investigation',
                description: $investigation->findings_summary
                    ? str($investigation->findings_summary)->limit(140)->toString()
                    : null,
            );
        })->all();
    }
}
