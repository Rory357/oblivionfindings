<?php

namespace App\Services\Sites\Profile;

use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Asset;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteTypePlanService;
use App\Support\ChecklistsDashboardData;
use Illuminate\Database\Eloquent\Builder;

class SiteProfileOperationsPresenter
{
    public function __construct(
        private readonly SiteTypePlanService $typePlans,
        private readonly DeviceRegistryService $devices,
    ) {}

    /** @return array<string, mixed> */
    public function calendar(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('calendar.view');

        return [
            'locked' => ! $canView,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'people' => $canView ? User::query()->staff()->orderBy('name')->get(['id', 'name']) : collect(),
            'canCreate' => $canView && ! $site->archived && $user->canDo('calendar.create') && $user->can('update', $site),
            'canManage' => $canView && ! $site->archived && $user->canDo('calendar.manage') && $user->can('update', $site),
            'canApprove' => $canView && ! $site->archived && $user->canDo('calendar.approve') && $user->can('update', $site),
            'feedUrl' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function checklists(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('checklists.view');
        $workspace = $canView ? (new ChecklistsDashboardData(request()))->forSite($site) : [];

        return [
            'locked' => ! $canView,
            ...$workspace,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'backHref' => route('sites.show', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function mealPlanner(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $locked = $site->archived || ! $user->canDo('sites.meals.view') || $site->type === 'head_office';

        return [
            'locked' => $locked,
            'href' => $locked ? null : route('sites.meals.plan.index', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function assets(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $this->canViewAssets($user);
        $query = Asset::query()->where('site_id', $site->id);
        $items = $canView
            ? (clone $query)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'asset_tag', 'category', 'status', 'risk_level', 'location', 'inspection_due_at', 'maintenance_due_at'])
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                    'category' => $asset->category,
                    'status' => $asset->status,
                    'risk_level' => $asset->risk_level,
                    'location' => $asset->location,
                    'inspection_due_at' => $asset->inspection_due_at?->toDateString(),
                    'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
                    'href' => route('fleet-assets.assets.show', $asset),
                ])->values()
            : collect();

        return [
            'locked' => ! $canView,
            'items' => $items,
            'summary' => $canView ? ['total' => (clone $query)->count(), 'shown' => $items->count()] : null,
            'href' => $canView ? route('fleet-assets.assets.index', ['site_id' => $site->id]) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function fleet(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('fleet.viewAny');
        $count = $canView
            ? Asset::query()
                ->vehicles()
                ->where(fn (Builder $query) => $query->where('site_id', $site->id)->orWhere('home_site_id', $site->id))
                ->count()
            : 0;

        return [
            'locked' => ! $canView,
            'summary' => $canView ? ['vehicles' => $count] : null,
            'href' => $canView ? route('fleet-assets.dashboard', ['site_id' => $site->id]) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function hardware(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('securityDevices.devices.view');
        $tenantId = (int) ($site->tenant_id ?? $user->tenant_id ?? $user->organization_id ?? 1);
        $count = $canView ? $this->devices->forSite($tenantId, $site->id)->count() : 0;

        return [
            'locked' => ! $canView,
            'summary' => $canView ? ['total' => $count] : null,
            'href' => $canView ? route('sites.hardware.index', $site) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function plan(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canViewHardware = $user->canDo('securityDevices.devices.view');
        $tenantId = (int) ($site->tenant_id ?? $user->tenant_id ?? $user->organization_id ?? 1);
        $hardwareCount = $canViewHardware ? $this->devices->forSite($tenantId, $site->id)->count() : null;
        $plan = $this->typePlans->profileSummaryFor($site, $hardwareCount);

        return [
            'locked' => false,
            'summary' => $plan['summary'],
            'href' => route('sites.plan.show', $site),
            'inventory_href' => $plan['inventory_href'],
            'inventory_label' => $plan['inventory_label'],
        ];
    }

    private function primePermissions(User $user): void
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
    }

    private function canViewAssets(User $user): bool
    {
        return $user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned');
    }
}
