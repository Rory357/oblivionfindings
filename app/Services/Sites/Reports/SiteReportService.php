<?php

namespace App\Services\Sites\Reports;

use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;

/**
 * The numbers behind every Site report, built from records that already
 * exist (hazards, checklist runs, inspection schedules/records, assets and
 * their maintenance logs). Each report answers three questions:
 *
 *   1. Are our sites safe?            open and serious hazards, overdue corrective actions
 *   2. Are checks done on time?       checklists + inspections done by their due date
 *   3. Where is maintenance slipping? assets past their maintenance date, maintenance done late
 *
 * Only Sites the viewer can access are ever read (UserSiteAccessService,
 * same bypass as the Sites module), narrowed to the Site types they may see.
 *
 * "Now" figures (open, overdue) are as of today. "Period" figures use the
 * report's date range. A check is due on its scheduled/due date; it is on
 * time when finished on or before that NZ day, late when finished after it,
 * and missed when it is still not done after it. Checks due today and not
 * yet done are left out of the rate. A rate with nothing due is null — the
 * page says "Nothing due", never 0%.
 */
final class SiteReportService
{
    /** Same bypass as the Sites module's cross-site pages. */
    public const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    /** The three report packs and the Site types each one covers. */
    public const PACKS = [
        'houses' => ['label' => 'Houses', 'types' => ['house', 'residential']],
        'facilities' => ['label' => 'Facilities', 'types' => ['facility']],
        'head-office' => ['label' => 'Head office', 'types' => ['head_office']],
    ];

    public const OPEN_HAZARD_STATUSES = ['open', 'in_progress', 'reopened'];

    public const CLOSED_HAZARD_STATUSES = ['mitigated', 'closed'];

    /** Asset statuses that are no longer in use, so their maintenance can't slip. */
    public const RETIRED_ASSET_STATUSES = ['retired', 'disposed', 'sold', 'written_off', 'lost'];

    /** Asset statuses that mean "not usable right now". */
    public const OUT_OF_SERVICE_STATUSES = ['out_of_service', 'maintenance', 'in_repair', 'repair'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SiteTypeAccessService $siteTypes,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Scope                                                              */
    /* ------------------------------------------------------------------ */

    /** @return array<int, int> */
    public function accessibleSiteIds(?User $user): array
    {
        return $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
    }

    /** @return array<int, string> */
    public function allowedTypes(?User $user): array
    {
        return $this->siteTypes->allowedTypes($user);
    }

    public function packKeyForType(?string $type): string
    {
        foreach (self::PACKS as $key => $pack) {
            if (in_array($type, $pack['types'], true)) {
                return $key;
            }
        }

        return 'houses';
    }

    /** True when the viewer may see at least one of the pack's Site types. */
    public function canSeePack(?User $user, string $pack): bool
    {
        $types = self::PACKS[$pack]['types'] ?? [];

        return array_intersect($types, $this->allowedTypes($user)) !== [];
    }

    /**
     * Every Site the viewer may report on (all regions), optionally narrowed
     * to some Site types.
     *
     * @param  array<int, string>|null  $types
     * @return Collection<int, Site>
     */
    public function accessibleSites(?User $user, ?array $types = null): Collection
    {
        $ids = $this->accessibleSiteIds($user);
        if ($ids === []) {
            return collect();
        }

        $allowed = $this->allowedTypes($user);
        $types = $types === null ? $allowed : array_values(array_intersect($types, $allowed));
        if ($types === []) {
            return collect();
        }

        return Site::query()
            ->whereIn('id', $ids)
            ->whereIn('type', $types)
            ->active()
            ->notArchived()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'region', 'city', 'suburb']);
    }

    /**
     * The Sites a report covers: accessible, of the given types, in the
     * filter's region.
     *
     * @param  array<int, string>|null  $types
     * @return Collection<int, Site>
     */
    public function scopedSites(?User $user, SiteReportFilters $filters, ?array $types = null): Collection
    {
        return $this->inRegion($this->accessibleSites($user, $types), $filters->region);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, Site>
     */
    public function inRegion(Collection $sites, ?string $region): Collection
    {
        if ($region === null) {
            return $sites->values();
        }

        return $sites->filter(fn (Site $site) => $site->resolved_region === $region)->values();
    }

    /**
     * Regions the viewer's Sites are in — the region filter's options.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, string>
     */
    public function regions(Collection $sites): array
    {
        return $sites
            ->map(fn (Site $site) => $site->resolved_region)
            ->filter(fn ($region) => is_string($region) && $region !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array{id: int, name: string, type: string, type_label: string, region: string|null, pack: string} */
    public function siteRow(Site $site): array
    {
        return [
            'id' => (int) $site->id,
            'name' => (string) $site->name,
            'type' => (string) $site->type,
            'type_label' => self::typeLabel($site->type),
            'region' => $site->resolved_region,
            'pack' => $this->packKeyForType($site->type),
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'house', 'residential' => 'House',
            'facility' => 'Facility',
            'head_office' => 'Head office',
            default => 'Site',
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Metrics                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array<string, int|float|null> */
    public static function emptyMetrics(): array
    {
        return [
            // Are our sites safe?
            'hazards_open' => 0,
            'hazards_serious' => 0,
            'actions_overdue' => 0,
            'hazards_raised' => 0,
            'hazards_closed' => 0,
            'avg_days_to_close' => null,
            // Are checks done on time?
            'checklists_due' => 0,
            'checklists_on_time' => 0,
            'checklists_late' => 0,
            'checklists_missed' => 0,
            'checklist_items_failed' => 0,
            'inspections_due' => 0,
            'inspections_on_time' => 0,
            'inspections_late' => 0,
            'inspections_missed' => 0,
            'inspections_failed' => 0,
            'inspections_overdue_now' => 0,
            'checks_due' => 0,
            'checks_on_time' => 0,
            'checks_late' => 0,
            'checks_missed' => 0,
            'checks_on_time_rate' => null,
            'checklists_on_time_rate' => null,
            'inspections_on_time_rate' => null,
            // Where is maintenance slipping?
            'assets' => 0,
            'assets_out_of_service' => 0,
            'maintenance_overdue_now' => 0,
            'maintenance_due' => 0,
            'maintenance_on_time' => 0,
            'maintenance_late' => 0,
            'maintenance_missed' => 0,
            'maintenance_on_time_rate' => null,
            'maintenance_done' => 0,
            'maintenance_spend' => 0.0,
        ];
    }

    /**
     * Metrics per Site, the totals across them, and the trend over the
     * period's buckets.
     *
     * @param  array<int, int>  $siteIds
     * @return array{sites: array<int, array<string, int|float|null>>, totals: array<string, int|float|null>, trend: array<int, array<string, int|float|string|null>>}
     */
    public function report(array $siteIds, SiteReportFilters $filters): array
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        $sites = [];
        foreach ($siteIds as $id) {
            $sites[$id] = self::emptyMetrics();
        }
        $trend = [];
        foreach ($filters->buckets() as $bucket) {
            $trend[$bucket['key']] = [
                ...$bucket,
                'checks_due' => 0,
                'checks_on_time' => 0,
                'checklists_due' => 0,
                'checklists_on_time' => 0,
                'inspections_due' => 0,
                'inspections_on_time' => 0,
                'hazards_raised' => 0,
                'maintenance_due' => 0,
                'maintenance_on_time' => 0,
                'maintenance_done' => 0,
            ];
        }

        if ($siteIds === []) {
            return ['sites' => [], 'totals' => self::finalise(self::emptyMetrics()), 'trend' => $this->finaliseTrend($trend)];
        }

        $closeDays = [];
        $this->addHazards($siteIds, $filters, $sites, $trend, $closeDays);
        $this->addChecklists($siteIds, $filters, $sites, $trend);
        $this->addInspections($siteIds, $filters, $sites, $trend);
        $this->addMaintenance($siteIds, $filters, $sites, $trend);

        $totals = self::emptyMetrics();
        $allCloseDays = [];
        foreach ($sites as $id => $metrics) {
            $days = $closeDays[$id] ?? [];
            $metrics['avg_days_to_close'] = $days === [] ? null : round(array_sum($days) / count($days), 1);
            $allCloseDays = [...$allCloseDays, ...$days];
            $sites[$id] = self::finalise($metrics);
            foreach ($metrics as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    if (! in_array($key, ['avg_days_to_close', 'checks_on_time_rate', 'checklists_on_time_rate', 'inspections_on_time_rate', 'maintenance_on_time_rate'], true)) {
                        $totals[$key] += $value;
                    }
                }
            }
        }
        $totals['avg_days_to_close'] = $allCloseDays === [] ? null : round(array_sum($allCloseDays) / count($allCloseDays), 1);

        return [
            'sites' => $sites,
            'totals' => self::finalise($totals),
            'trend' => $this->finaliseTrend($trend),
        ];
    }

    /**
     * @param  array<string, int|float|null>  $m
     * @return array<string, int|float|null>
     */
    private static function finalise(array $m): array
    {
        $m['checks_due'] = $m['checklists_due'] + $m['inspections_due'];
        $m['checks_on_time'] = $m['checklists_on_time'] + $m['inspections_on_time'];
        $m['checks_late'] = $m['checklists_late'] + $m['inspections_late'];
        $m['checks_missed'] = $m['checklists_missed'] + $m['inspections_missed'];
        $m['checks_on_time_rate'] = self::rate($m['checks_on_time'], $m['checks_due']);
        $m['checklists_on_time_rate'] = self::rate($m['checklists_on_time'], $m['checklists_due']);
        $m['inspections_on_time_rate'] = self::rate($m['inspections_on_time'], $m['inspections_due']);
        $m['maintenance_on_time_rate'] = self::rate($m['maintenance_on_time'], $m['maintenance_due']);
        $m['maintenance_spend'] = round((float) $m['maintenance_spend'], 2);

        return $m;
    }

    /**
     * Sum per-Site metrics (e.g. one pack's Sites) and work the rates out
     * again. Average days to close can't be re-averaged, so it is left null.
     *
     * @param  iterable<array<string, int|float|null>>  $rows
     * @return array<string, int|float|null>
     */
    public static function aggregate(iterable $rows): array
    {
        $totals = self::emptyMetrics();
        foreach ($rows as $row) {
            foreach (['hazards_open', 'hazards_serious', 'actions_overdue', 'hazards_raised', 'hazards_closed',
                'checklists_due', 'checklists_on_time', 'checklists_late', 'checklists_missed', 'checklist_items_failed',
                'inspections_due', 'inspections_on_time', 'inspections_late', 'inspections_missed', 'inspections_failed',
                'inspections_overdue_now', 'assets', 'assets_out_of_service', 'maintenance_overdue_now', 'maintenance_due',
                'maintenance_on_time', 'maintenance_late', 'maintenance_missed', 'maintenance_done', 'maintenance_spend'] as $key) {
                $totals[$key] += $row[$key] ?? 0;
            }
        }

        return self::finalise($totals);
    }

    /** 403 unless the viewer can access this Site and see its type. */
    public function assertCanReportOn(?User $user, Site $site): void
    {
        $this->siteAccess->assertCanAccessSiteId($user, (int) $site->id, self::SITE_BYPASS_PERMISSIONS);
        abort_unless(in_array($site->type, $this->allowedTypes($user), true), 403);
    }

    public static function rate(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : null;
    }

    /**
     * @param  array<string, array<string, int|float|string|null>>  $trend
     * @return array<int, array<string, int|float|string|null>>
     */
    private function finaliseTrend(array $trend): array
    {
        return array_values(array_map(function (array $bucket) {
            $bucket['checks_on_time_rate'] = self::rate($bucket['checks_on_time'], $bucket['checks_due']);
            $bucket['checklists_on_time_rate'] = self::rate($bucket['checklists_on_time'], $bucket['checklists_due']);
            $bucket['inspections_on_time_rate'] = self::rate($bucket['inspections_on_time'], $bucket['inspections_due']);
            $bucket['maintenance_on_time_rate'] = self::rate($bucket['maintenance_on_time'], $bucket['maintenance_due']);

            return $bucket;
        }, $trend));
    }

    /**
     * Classify one check against its due day.
     *
     * @return 'on_time'|'late'|'missed'|'pending'
     */
    public static function checkOutcome(string $dueDate, bool $done, ?string $doneDate, string $today): string
    {
        if ($done) {
            return $doneDate === null || $doneDate <= $dueDate ? 'on_time' : 'late';
        }

        return $dueDate < $today ? 'missed' : 'pending';
    }

    /**
     * @param  array<int, int>  $siteIds
     * @param  array<int, array<string, int|float|null>>  $sites
     * @param  array<string, array<string, int|float|string|null>>  $trend
     * @param  array<int, array<int, float>>  $closeDays
     */
    private function addHazards(array $siteIds, SiteReportFilters $f, array &$sites, array &$trend, array &$closeDays): void
    {
        $fromUtc = $f->fromUtc()->toDateTimeString();
        $toUtc = $f->toUtc()->toDateTimeString();
        $today = $f->todayDate();

        $rows = SiteHazard::query()
            ->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q
                ->whereIn('status', self::OPEN_HAZARD_STATUSES)
                ->orWhereBetween('created_at', [$fromUtc, $toUtc])
                ->orWhereBetween('closed_at', [$fromUtc, $toUtc]))
            ->toBase()
            ->get(['site_id', 'status', 'severity', 'risk_rating', 'due_date', 'created_at', 'closed_at']);

        foreach ($rows as $row) {
            $id = (int) $row->site_id;
            if (! isset($sites[$id])) {
                continue;
            }
            $open = in_array($row->status, self::OPEN_HAZARD_STATUSES, true);
            if ($open) {
                $sites[$id]['hazards_open']++;
                if (in_array($row->severity, ['high', 'critical'], true) || in_array($row->risk_rating, ['high', 'extreme'], true)) {
                    $sites[$id]['hazards_serious']++;
                }
                if ($row->due_date && substr((string) $row->due_date, 0, 10) < $today) {
                    $sites[$id]['actions_overdue']++;
                }
            }
            $raisedOn = SiteReportFilters::localDate($row->created_at);
            if ($raisedOn !== null && $f->inPeriod($raisedOn)) {
                $sites[$id]['hazards_raised']++;
                $key = $f->bucketKey($raisedOn);
                if (isset($trend[$key])) {
                    $trend[$key]['hazards_raised']++;
                }
            }
            $closedOn = SiteReportFilters::localDate($row->closed_at);
            if (! $open && $closedOn !== null && $f->inPeriod($closedOn) && in_array($row->status, self::CLOSED_HAZARD_STATUSES, true)) {
                $sites[$id]['hazards_closed']++;
                if ($row->created_at) {
                    $closeDays[$id][] = max(0.0, (strtotime((string) $row->closed_at) - strtotime((string) $row->created_at)) / 86400);
                }
            }
        }
    }

    /**
     * @param  array<int, int>  $siteIds
     * @param  array<int, array<string, int|float|null>>  $sites
     * @param  array<string, array<string, int|float|string|null>>  $trend
     */
    private function addChecklists(array $siteIds, SiteReportFilters $f, array &$sites, array &$trend): void
    {
        $today = $f->todayDate();
        $rows = SiteChecklistRun::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('scheduled_date', [$f->fromDate(), $f->dueUntil()])
            ->where('status', '!=', 'skipped')
            ->toBase()
            ->get(['site_id', 'scheduled_date', 'status', 'completed_at', 'items_failed']);

        foreach ($rows as $row) {
            $id = (int) $row->site_id;
            if (! isset($sites[$id])) {
                continue;
            }
            $due = substr((string) $row->scheduled_date, 0, 10);
            $done = $row->status === 'completed';
            $outcome = self::checkOutcome($due, $done, SiteReportFilters::localDate($row->completed_at), $today);
            if ($done) {
                $sites[$id]['checklist_items_failed'] += (int) $row->items_failed;
            }
            if ($outcome === 'pending') {
                continue;
            }
            $sites[$id]['checklists_due']++;
            $sites[$id]['checklists_'.$outcome]++;
            $key = $f->bucketKey($due);
            if (isset($trend[$key])) {
                $trend[$key]['checklists_due']++;
                $trend[$key]['checks_due']++;
                if ($outcome === 'on_time') {
                    $trend[$key]['checklists_on_time']++;
                    $trend[$key]['checks_on_time']++;
                }
            }
        }
    }

    /**
     * Inspection records are written when an inspection is done (due date =
     * the schedule's due date at the time); a schedule still past its due
     * date is the missed one.
     *
     * @param  array<int, int>  $siteIds
     * @param  array<int, array<string, int|float|null>>  $sites
     * @param  array<string, array<string, int|float|string|null>>  $trend
     */
    private function addInspections(array $siteIds, SiteReportFilters $f, array &$sites, array &$trend): void
    {
        $today = $f->todayDate();

        $records = SiteInspectionRecord::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('due_date', [$f->fromDate(), $f->dueUntil()])
            ->toBase()
            ->get(['site_id', 'due_date', 'completed_at', 'result']);

        foreach ($records as $row) {
            $id = (int) $row->site_id;
            if (! isset($sites[$id])) {
                continue;
            }
            $due = substr((string) $row->due_date, 0, 10);
            $done = $row->completed_at !== null;
            $outcome = self::checkOutcome($due, $done, SiteReportFilters::localDate($row->completed_at), $today);
            if ($done && $row->result === 'fail') {
                $sites[$id]['inspections_failed']++;
            }
            if ($outcome === 'pending') {
                continue;
            }
            $this->countInspection($sites[$id], $trend, $f->bucketKey($due), $outcome);
        }

        $overdue = SiteInspectionSchedule::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', $today)
            ->toBase()
            ->get(['site_id', 'next_due_date']);

        foreach ($overdue as $row) {
            $id = (int) $row->site_id;
            if (! isset($sites[$id])) {
                continue;
            }
            $sites[$id]['inspections_overdue_now']++;
            $due = substr((string) $row->next_due_date, 0, 10);
            if ($due >= $f->fromDate() && $due <= $f->dueUntil()) {
                $this->countInspection($sites[$id], $trend, $f->bucketKey($due), 'missed');
            }
        }
    }

    /**
     * @param  array<string, int|float|null>  $site
     * @param  array<string, array<string, int|float|string|null>>  $trend
     */
    private function countInspection(array &$site, array &$trend, string $key, string $outcome): void
    {
        $site['inspections_due']++;
        $site['inspections_'.$outcome]++;
        if (isset($trend[$key])) {
            $trend[$key]['inspections_due']++;
            $trend[$key]['checks_due']++;
            if ($outcome === 'on_time') {
                $trend[$key]['inspections_on_time']++;
                $trend[$key]['checks_on_time']++;
            }
        }
    }

    /**
     * Maintenance is "due" on a log's next-due date; it was done on time when
     * the asset's next log is on or before that day.
     *
     * @param  array<int, int>  $siteIds
     * @param  array<int, array<string, int|float|null>>  $sites
     * @param  array<string, array<string, int|float|string|null>>  $trend
     */
    private function addMaintenance(array $siteIds, SiteReportFilters $f, array &$sites, array &$trend): void
    {
        $today = $f->todayDate();
        $assets = Asset::query()
            ->whereIn('site_id', $siteIds)
            ->toBase()
            ->get(['id', 'site_id', 'status', 'maintenance_due_at']);

        $siteOfAsset = [];
        foreach ($assets as $asset) {
            $id = (int) $asset->site_id;
            if (! isset($sites[$id])) {
                continue;
            }
            if (in_array($asset->status, self::RETIRED_ASSET_STATUSES, true)) {
                continue;
            }
            $siteOfAsset[(int) $asset->id] = $id;
            $sites[$id]['assets']++;
            if (in_array($asset->status, self::OUT_OF_SERVICE_STATUSES, true)) {
                $sites[$id]['assets_out_of_service']++;
            }
            if ($asset->maintenance_due_at && substr((string) $asset->maintenance_due_at, 0, 10) < $today) {
                $sites[$id]['maintenance_overdue_now']++;
            }
        }

        if ($siteOfAsset === []) {
            return;
        }

        foreach ($this->maintenanceOutcomes(array_keys($siteOfAsset), $f) as $outcome) {
            $id = $siteOfAsset[$outcome['asset_id']] ?? null;
            if ($id === null) {
                continue;
            }
            if ($outcome['kind'] === 'done') {
                $sites[$id]['maintenance_done']++;
                $sites[$id]['maintenance_spend'] += $outcome['cost'];
                $key = $f->bucketKey($outcome['date']);
                if (isset($trend[$key])) {
                    $trend[$key]['maintenance_done']++;
                }

                continue;
            }
            $sites[$id]['maintenance_due']++;
            $sites[$id]['maintenance_'.$outcome['kind']]++;
            $key = $f->bucketKey($outcome['date']);
            if (isset($trend[$key])) {
                $trend[$key]['maintenance_due']++;
                if ($outcome['kind'] === 'on_time') {
                    $trend[$key]['maintenance_on_time']++;
                }
            }
        }
    }

    /**
     * Maintenance done in the period ('done', dated by when it was done) and
     * maintenance that fell due in the period ('on_time' | 'late' | 'missed',
     * dated by its due day).
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, array{asset_id: int, kind: string, date: string, cost: float, log_id: int}>
     */
    public function maintenanceOutcomes(array $assetIds, SiteReportFilters $f): array
    {
        if ($assetIds === []) {
            return [];
        }
        $today = $f->todayDate();

        $dueLogs = AssetMaintenanceLog::query()
            ->whereIn('asset_id', $assetIds)
            ->whereNotNull('next_due_at')
            ->whereBetween('next_due_at', [$f->fromDate(), $f->dueUntil()])
            ->toBase()
            ->get(['id', 'asset_id', 'performed_at', 'next_due_at']);

        $earliest = $f->fromUtc()->toDateTimeString();
        foreach ($dueLogs as $log) {
            if ($log->performed_at && (string) $log->performed_at < $earliest) {
                $earliest = (string) $log->performed_at;
            }
        }

        $logs = AssetMaintenanceLog::query()
            ->whereIn('asset_id', $assetIds)
            ->whereNotNull('performed_at')
            ->where('performed_at', '>=', $earliest)
            ->orderBy('performed_at')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'asset_id', 'performed_at', 'cost']);

        $byAsset = [];
        $out = [];
        foreach ($logs as $log) {
            $byAsset[(int) $log->asset_id][] = $log;
            $doneOn = SiteReportFilters::localDate($log->performed_at);
            if ($doneOn !== null && $f->inPeriod($doneOn)) {
                $out[] = ['asset_id' => (int) $log->asset_id, 'kind' => 'done', 'date' => $doneOn, 'cost' => (float) ($log->cost ?? 0), 'log_id' => (int) $log->id];
            }
        }

        foreach ($dueLogs as $due) {
            $dueOn = substr((string) $due->next_due_at, 0, 10);
            $followUp = null;
            foreach ($byAsset[(int) $due->asset_id] ?? [] as $candidate) {
                if ((int) $candidate->id === (int) $due->id) {
                    continue;
                }
                if ($due->performed_at === null || (string) $candidate->performed_at > (string) $due->performed_at) {
                    $followUp = $candidate;
                    break;
                }
            }
            $kind = self::checkOutcome($dueOn, $followUp !== null, SiteReportFilters::localDate($followUp?->performed_at), $today);
            if ($kind === 'pending') {
                continue;
            }
            $out[] = ['asset_id' => (int) $due->asset_id, 'kind' => $kind, 'date' => $dueOn, 'cost' => 0.0, 'log_id' => (int) $due->id];
        }

        return $out;
    }
}
