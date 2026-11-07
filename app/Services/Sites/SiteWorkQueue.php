<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The overdue work behind the Sites list's "Overdue" meter, and the choices
 * the Sites-list pop-ups offer for one Site.
 *
 * Every number here uses the same rules as the Sites list header
 * (SiteController::index): the viewer's live (non-archived) Sites in the
 * Site types they may see; checklist runs past their scheduled date and
 * still open; active inspection schedules past their next due date; assets
 * with scheduled servicing due today or earlier. Lists are capped at
 * LIMIT rows each (most overdue first) and always carry their real total.
 *
 * Action flags mirror the endpoints they open, which still enforce them:
 * recording a result needs checklists.run plus SitePolicy::update on the
 * Site; logging maintenance needs assets.maintenance.record plus
 * AssetPolicy (the canonical asset access query).
 */
final class SiteWorkQueue
{
    public const LIMIT = 50;

    public const OPTION_LIMIT = 50;

    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SiteTypeAccessService $siteTypes,
        private readonly SecurityDevicesAccessService $assetAccess,
    ) {}

    /**
     * The live Sites on this viewer's Sites list (same scope as the header).
     *
     * @return list<int>
     */
    public function siteIds(User $user): array
    {
        $query = Site::query()
            ->whereIn('type', $this->siteTypes->allowedTypes($user))
            ->where('archived', false);

        if (! $this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            $accessible = $this->siteAccess->accessibleSiteIds($user);
            if ($accessible === []) {
                return [];
            }
            $query->whereIn('id', $accessible);
        }

        return $query->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $siteIds
     */
    public function overdue(User $user, array $siteIds): array
    {
        $today = now()->toDateString();

        return [
            'limit' => self::LIMIT,
            'checklists' => $this->overdueChecklists($user, $siteIds, $today),
            'inspections' => $this->overdueInspections($user, $siteIds, $today),
            'maintenance' => $this->overdueMaintenance($user, $siteIds, $today),
        ];
    }

    /**
     * Assets with servicing due today or earlier, per Site.
     *
     * @param  list<int>  $siteIds
     * @return list<array{site_id: int, count: int}>
     */
    public function maintenanceDueBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return $this->maintenanceDueQuery($siteIds, now()->toDateString())
            ->selectRaw('site_id, COUNT(*) as aggregate')
            ->groupBy('site_id')
            ->get()
            ->map(fn (Asset $row): array => [
                'site_id' => (int) $row->site_id,
                'count' => (int) $row->getAttribute('aggregate'),
            ])
            ->values()
            ->all();
    }

    /** Active inspection schedules at one Site, for "Record inspection result". */
    public function scheduleOptions(Site $site): array
    {
        $today = now()->toDateString();
        $query = SiteInspectionSchedule::query()
            ->where('site_id', $site->id)
            ->where('is_active', true);

        $total = (clone $query)->count();
        $items = $query
            ->orderBy('next_due_date')
            ->orderBy('title')
            ->limit(self::OPTION_LIMIT)
            ->get(['id', 'site_id', 'title', 'inspection_type', 'frequency', 'next_due_date'])
            ->map(fn (SiteInspectionSchedule $schedule): array => [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'inspection_type' => $schedule->inspection_type,
                'frequency' => $schedule->frequency,
                'due_date' => $schedule->next_due_date?->toDateString(),
                // Overdue from the day after the due date; null when not overdue.
                'days_overdue' => $this->atLeast($this->daysPastDue($schedule->next_due_date, $today), 1),
            ])
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Assets at one Site this viewer may log maintenance for, overdue first.
     * Uses the canonical asset access query (the one AssetPolicy checks), so
     * nothing listed here is refused on save.
     */
    public function assetOptions(User $user, Site $site, ?string $search): array
    {
        $today = now()->toDateString();
        $search = trim((string) $search);

        $query = $this->assetAccess->accessibleAssets($user);
        $query->where($query->qualifyColumn('site_id'), $site->id)
            ->when($search !== '', function (Builder $assets) use ($search): void {
                $assets->where(function (Builder $match) use ($search): void {
                    $match->where($match->qualifyColumn('name'), 'like', "%{$search}%")
                        ->orWhere($match->qualifyColumn('asset_tag'), 'like', "%{$search}%");
                });
            });

        $total = (clone $query)->count();
        $items = $query
            ->orderByRaw(
                'CASE WHEN requires_maintenance = 1 AND maintenance_due_at IS NOT NULL AND maintenance_due_at <= ? THEN 0 ELSE 1 END',
                [$today],
            )
            ->orderByRaw('CASE WHEN maintenance_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy($query->qualifyColumn('maintenance_due_at'))
            ->orderBy($query->qualifyColumn('name'))
            ->limit(self::OPTION_LIMIT)
            ->get()
            ->map(fn (Asset $asset): array => [
                'id' => $asset->id,
                'name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'due_date' => $asset->requires_maintenance ? $asset->maintenance_due_at?->toDateString() : null,
                // Due from the due day itself (0 = due today); null when not due.
                'days_overdue' => $asset->requires_maintenance
                    ? $this->atLeast($this->daysPastDue($asset->maintenance_due_at, $today), 0)
                    : null,
            ])
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /** @param list<int> $siteIds */
    private function overdueChecklists(User $user, array $siteIds, string $today): array
    {
        $query = SiteChecklistRun::query()
            ->whereIn('site_id', $siteIds === [] ? [0] : $siteIds)
            ->overdue();

        $total = (clone $query)->count();
        $items = $query
            ->with(['site:id,name,type', 'template:id,name'])
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'site_id', 'template_id', 'scheduled_date', 'status'])
            ->map(fn (SiteChecklistRun $run): array => [
                'id' => $run->id,
                'site' => $this->sitePayload($run->site, (int) $run->site_id),
                'title' => $run->template?->name ?? 'Checklist',
                'due_date' => $run->scheduled_date?->toDateString(),
                'days_overdue' => $this->overdueDays($run->scheduled_date, $today),
                'in_progress' => $run->status === 'in_progress',
                // The checklist runner is a full page (one answer per item).
                'href' => "/sites/{$run->site_id}/checklists?run={$run->id}",
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'items' => $items,
            'can_complete' => $user->canDo('checklists.run'),
        ];
    }

    /** @param list<int> $siteIds */
    private function overdueInspections(User $user, array $siteIds, string $today): array
    {
        $query = SiteInspectionSchedule::query()
            ->whereIn('site_id', $siteIds === [] ? [0] : $siteIds)
            ->where('is_active', true)
            ->whereDate('next_due_date', '<', $today);

        $total = (clone $query)->count();
        $schedules = $query
            ->with('site:id,name,type,archived')
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'site_id', 'title', 'inspection_type', 'frequency', 'next_due_date']);

        $recordableSiteIds = $this->recordableSiteIds($user, $schedules->pluck('site')->filter());

        $items = $schedules
            ->map(fn (SiteInspectionSchedule $schedule): array => [
                'id' => $schedule->id,
                'site' => $this->sitePayload($schedule->site, (int) $schedule->site_id),
                'title' => $schedule->title,
                'inspection_type' => $schedule->inspection_type,
                'frequency' => $schedule->frequency,
                'due_date' => $schedule->next_due_date?->toDateString(),
                'days_overdue' => $this->overdueDays($schedule->next_due_date, $today),
                'can_record' => in_array((int) $schedule->site_id, $recordableSiteIds, true),
            ])
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /** @param list<int> $siteIds */
    private function overdueMaintenance(User $user, array $siteIds, string $today): array
    {
        $query = $this->maintenanceDueQuery($siteIds === [] ? [0] : $siteIds, $today);

        $total = (clone $query)->count();
        $assets = $query
            ->with('site:id,name,type')
            ->orderBy('maintenance_due_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'site_id', 'name', 'asset_tag', 'maintenance_due_at']);

        $loggableIds = [];
        if ($assets->isNotEmpty() && $user->canDo('assets.maintenance.record')) {
            $accessible = $this->assetAccess->accessibleAssets($user);
            $key = $accessible->getModel()->getQualifiedKeyName();
            $loggableIds = $accessible
                ->whereIn($key, $assets->pluck('id')->all())
                ->pluck($key)
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        $items = $assets
            ->map(fn (Asset $asset): array => [
                'id' => $asset->id,
                'site' => $this->sitePayload($asset->site, (int) $asset->site_id),
                'name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'due_date' => $asset->maintenance_due_at?->toDateString(),
                'days_overdue' => $this->overdueDays($asset->maintenance_due_at, $today),
                'can_log' => in_array((int) $asset->id, $loggableIds, true),
            ])
            ->values()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /** @param list<int> $siteIds */
    private function maintenanceDueQuery(array $siteIds, string $today): Builder
    {
        return Asset::query()
            ->whereIn('site_id', $siteIds)
            ->where('requires_maintenance', true)
            ->whereNotNull('maintenance_due_at')
            ->whereDate('maintenance_due_at', '<=', $today);
    }

    /**
     * Sites (among those listed) where this viewer may record an inspection
     * result: the route's checklists.run plus the controller's
     * SitePolicy::update (which also refuses archived Sites).
     *
     * @param  Collection<int, Site>  $sites
     * @return list<int>
     */
    private function recordableSiteIds(User $user, Collection $sites): array
    {
        if (! $user->canDo('checklists.run')) {
            return [];
        }

        return $sites
            ->unique('id')
            ->filter(fn (Site $site): bool => Gate::forUser($user)->allows('update', $site))
            ->map(fn (Site $site): int => (int) $site->id)
            ->values()
            ->all();
    }

    private function sitePayload(?Site $site, int $siteId): array
    {
        return [
            'id' => $siteId,
            'name' => $site?->name,
            'type' => $site?->type,
        ];
    }

    /**
     * Whole days a due date is behind today: positive once past due, 0 on the
     * due day, negative before it. Checklist runs and inspections are overdue
     * from the day after they fall due; maintenance from the due day itself
     * (0 reads "due today").
     */
    private function daysPastDue(?CarbonInterface $due, string $today): ?int
    {
        if ($due === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($due->toDateString())
            ->diffInDays(CarbonImmutable::parse($today), false);
    }

    /** The day count when it reaches the threshold, otherwise null. */
    private function atLeast(?int $days, int $threshold): ?int
    {
        return $days !== null && $days >= $threshold ? $days : null;
    }

    /** Days past due for a row already known to be overdue (never negative). */
    private function overdueDays(?CarbonInterface $due, string $today): ?int
    {
        $days = $this->daysPastDue($due, $today);

        return $days === null ? null : max(0, $days);
    }
}
