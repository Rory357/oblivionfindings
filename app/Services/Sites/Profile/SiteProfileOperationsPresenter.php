<?php

namespace App\Services\Sites\Profile;

use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteChecklistRun;
use App\Models\User;
use App\Services\Sites\SiteTypePlanService;
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
        $items = $canView
            ? SiteCalendarEvent::query()
                ->where('site_id', $site->id)
                ->where('start_at', '>=', now()->startOfDay())
                ->orderBy('start_at')
                ->limit(12)
                ->get(['id', 'event_type', 'title', 'start_at', 'end_at', 'status'])
                ->map(fn (SiteCalendarEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'title' => $event->title,
                    'start_at' => $event->start_at?->toISOString(),
                    'end_at' => $event->end_at?->toISOString(),
                    'status' => $event->status,
                ])->values()
            : collect();

        return [
            'locked' => ! $canView,
            'items' => $items,
            'summary' => $canView ? ['upcoming' => $items->count()] : null,
            'href' => $canView ? route('sites.calendar.index', $site) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function checklists(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('checklists.view');
        $query = SiteChecklistRun::query()->where('site_id', $site->id);
        $items = $canView
            ? (clone $query)
                ->with('template:id,name')
                ->orderByDesc('scheduled_date')
                ->limit(12)
                ->get(['id', 'template_id', 'scheduled_date', 'status', 'completion_percentage', 'items_failed'])
                ->map(fn (SiteChecklistRun $run) => [
                    'id' => $run->id,
                    'name' => $run->template?->name,
                    'scheduled_date' => $run->scheduled_date?->toDateString(),
                    'status' => $run->status,
                    'completion_percentage' => (float) $run->completion_percentage,
                    'items_failed' => (int) $run->items_failed,
                    'href' => route('sites.checklists.showRun', $run),
                ])->values()
            : collect();
        $counts = $canView
            ? (clone $query)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as open_count")
                ->selectRaw("SUM(CASE WHEN scheduled_date < ? AND status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as overdue_count", [now()->toDateString()])
                ->selectRaw('SUM(CASE WHEN items_failed > 0 THEN 1 ELSE 0 END) as failed_count')
                ->first()
            : null;

        return [
            'locked' => ! $canView,
            'items' => $items,
            'summary' => $canView ? [
                'total' => (int) ($counts?->total ?? 0),
                'open' => (int) ($counts?->open_count ?? 0),
                'overdue' => (int) ($counts?->overdue_count ?? 0),
                'failed' => (int) ($counts?->failed_count ?? 0),
            ] : null,
            'href' => $canView ? route('sites.checklists.index', $site) : null,
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
