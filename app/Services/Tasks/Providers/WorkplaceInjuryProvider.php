<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\UserSiteAccessService;

class WorkplaceInjuryProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
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

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = WorkplaceInjury::query()
            ->with(['user:id,name', 'site:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('injury_date')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['recovered', 'closed']);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => app(UserSiteAccessService::class)->applyWorkplaceInjuryScope(
                $scoped,
                $actor,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            ),
            function (WorkplaceInjury $injury) {
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
            },
        );
    }
}
