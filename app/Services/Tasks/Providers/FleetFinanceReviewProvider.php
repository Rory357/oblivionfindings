<?php

namespace App\Services\Tasks\Providers;

use App\Models\FleetFinanceReviewRequest;
use App\Models\User;
use App\Services\Fleet\VehicleFinanceService;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

/**
 * Vehicle Finance review requests waiting for a Finance decision. Finance
 * users who can decide them see this source for vehicles at the Sites they
 * handle for Finance (VehicleFinanceService::financeSiteIds); no Fleet access
 * is needed. The row opens the request in Finance › Vehicle reviews.
 */
class FleetFinanceReviewProvider implements SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'fleet_finance_review';
    }

    public function label(): string
    {
        return 'Vehicle Finance Reviews';
    }

    public function canView(User $user): bool
    {
        return ($user->canDo('finance.assets.view') || $user->canDo('finance.ap.view'))
            && app(VehicleFinanceService::class)->canDecide($user);
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = FleetFinanceReviewRequest::query()
            ->with(['asset:id,name,site_id', 'asset.site:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->when(
                empty($filters['include_done']),
                fn ($q) => $q->where('status', 'submitted'),
                fn ($q) => $q->where(fn ($recent) => $recent->where('status', 'submitted')
                    ->orWhere('decided_at', '>=', now()->subDays(30))),
            )
            ->orderBy('created_at')
            ->limit(300);

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            // Finance's own Site rule, not the Fleet one.
            fn ($scoped, User $actor) => $scoped->whereHas('asset', fn ($asset) => $asset->whereNotNull('site_id')
                ->whereIn('site_id', app(VehicleFinanceService::class)->financeSiteIds($actor))),
            function (FleetFinanceReviewRequest $request) {
                $vehicle = $request->asset;
                $open = $request->isOpen();

                return new TaskItem(
                    id: 'fleet_finance_review-'.$request->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $request->reference_number,
                    title: $request->typeLabel().($vehicle ? ' — '.$vehicle->name : ''),
                    status: (string) $request->status,
                    bucket: $open ? TaskItem::BUCKET_OPEN : TaskItem::BUCKET_DONE,
                    severity: 'medium',
                    assignee: null,
                    site: $vehicle?->site ? ['id' => $vehicle->site->id, 'name' => (string) $vehicle->site->name] : null,
                    dueAt: null,
                    createdAt: optional($request->created_at)->toIso8601String(),
                    link: "/finance/vehicle-reviews?request={$request->id}",
                    type: 'Finance review request',
                    description: str($request->note)->limit(140)->toString(),
                    actionLabel: $open ? 'Review in Finance' : 'Open in Finance',
                    displayState: match ($request->status) {
                        'resolved' => 'Resolved',
                        'declined' => 'Declined',
                        default => 'Pending Finance review',
                    },
                );
            },
        );
    }
}
