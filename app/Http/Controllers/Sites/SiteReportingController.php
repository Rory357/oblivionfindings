<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\SiteVendor;
use App\Services\Sites\Reports\SiteReportFilters;
use App\Services\Sites\Reports\SiteReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site reports. Every report is built around three questions — are our
 * sites safe, are checks done on time, where is maintenance slipping — and
 * reads one shared scope from the query string (period, date range, region;
 * SiteReportFilters) so a filter carries from report to report. Only Sites
 * the viewer can access are counted (SiteReportService).
 */
class SiteReportingController extends Controller
{
    /** Per-Site numbers the hub and the packs show (keeps the payload small). */
    private const SITE_ROW_METRICS = [
        'hazards_open', 'hazards_serious', 'actions_overdue', 'hazards_raised',
        'checks_due', 'checks_on_time', 'checks_missed', 'checks_on_time_rate',
        'inspections_overdue_now', 'maintenance_overdue_now', 'maintenance_due',
        'maintenance_on_time_rate', 'assets', 'assets_out_of_service',
    ];

    public function __construct(
        private readonly SiteReportService $reports,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Hub                                                                */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $user = $request->user();
        $filters = SiteReportFilters::fromRequest($request);

        $accessible = $this->reports->accessibleSites($user);
        $sites = $this->reports->inRegion($accessible, $filters->region);
        $report = $this->reports->report($sites->pluck('id')->all(), $filters);

        $packs = [];
        foreach (SiteReportService::PACKS as $key => $pack) {
            if (! $this->reports->canSeePack($user, $key)) {
                continue;
            }
            $inPack = $sites->filter(fn (Site $site) => in_array($site->type, $pack['types'], true));
            $packTotals = SiteReportService::aggregate(
                $inPack->map(fn (Site $site) => $report['sites'][$site->id] ?? SiteReportService::emptyMetrics()),
            );
            $packs[] = [
                'key' => $key,
                'label' => $pack['label'],
                'href' => '/sites/reports/'.$key,
                'site_count' => $inPack->count(),
                'site_count_all' => $accessible->filter(fn (Site $site) => in_array($site->type, $pack['types'], true))->count(),
                'actions_overdue' => $packTotals['actions_overdue'],
                'checks_on_time_rate' => $packTotals['checks_on_time_rate'],
                'checks_due' => $packTotals['checks_due'],
                'maintenance_overdue_now' => $packTotals['maintenance_overdue_now'],
            ];
        }

        return inertia('sites/reports/index', [
            ...$this->scopeProps($request, $filters, $accessible),
            'totals' => $report['totals'],
            'trend' => $report['trend'],
            'packs' => $packs,
            'sites' => $sites->map(fn (Site $site) => [
                ...$this->reports->siteRow($site),
                'metrics' => $this->pickRowMetrics($report['sites'][$site->id] ?? SiteReportService::emptyMetrics()),
            ])->values(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Packs — Houses, Facilities, Head office                           */
    /* ------------------------------------------------------------------ */

    public function houses(Request $request)
    {
        return $this->pack($request, 'houses');
    }

    public function facilities(Request $request)
    {
        return $this->pack($request, 'facilities');
    }

    public function headOffice(Request $request)
    {
        return $this->pack($request, 'head-office');
    }

    private function pack(Request $request, string $pack)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        abort_unless($this->reports->canSeePack($request->user(), $pack), 403);
        $user = $request->user();
        $filters = SiteReportFilters::fromRequest($request);
        $types = SiteReportService::PACKS[$pack]['types'];

        $accessible = $this->reports->accessibleSites($user, $types);
        $sites = $this->reports->inRegion($accessible, $filters->region);
        $ids = $sites->pluck('id')->all();
        $report = $this->reports->report($ids, $filters);
        $extras = $this->packExtras($pack, $ids, $filters);

        $extraTotals = [];
        foreach ($extras as $siteExtras) {
            foreach ($siteExtras as $key => $value) {
                $extraTotals[$key] = ($extraTotals[$key] ?? 0) + $value;
            }
        }

        return inertia('sites/reports/pack', [
            ...$this->scopeProps($request, $filters, $accessible),
            'pack' => [
                'key' => $pack,
                'label' => SiteReportService::PACKS[$pack]['label'],
                'site_count_all' => $accessible->count(),
            ],
            'totals' => $report['totals'],
            'extraTotals' => $extraTotals,
            'trend' => $report['trend'],
            'sites' => $sites->map(fn (Site $site) => [
                ...$this->reports->siteRow($site),
                'metrics' => $this->pickRowMetrics($report['sites'][$site->id] ?? SiteReportService::emptyMetrics()),
                'extras' => $extras[$site->id] ?? [],
            ])->values(),
        ]);
    }

    /**
     * The pack's own facts per Site: bedrooms and clients for houses, zones
     * for facilities, rooms and bookings for head office.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>
     */
    private function packExtras(string $pack, array $ids, SiteReportFilters $filters): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Site::query()->whereIn('id', $ids)->select('id');
        $query = match ($pack) {
            'houses' => $query->withCount([
                'houseRooms as bedrooms' => fn ($q) => $q->active(),
                'clients as clients' => fn ($q) => $q->where('status', 'active'),
            ]),
            'facilities' => $query->withCount([
                'facilityZones as zones' => fn ($q) => $q->active(),
            ]),
            default => $query->withCount([
                'hoResources as rooms' => fn ($q) => $q->active(),
                'calendarEvents as room_bookings' => fn ($q) => $q
                    ->where('event_type', 'room_booking')
                    ->whereBetween('start_at', [$filters->fromUtc(), $filters->toUtc()]),
            ]),
        };

        return $query->get()->mapWithKeys(function (Site $site) use ($pack) {
            $keys = match ($pack) {
                'houses' => ['bedrooms', 'clients'],
                'facilities' => ['zones'],
                default => ['rooms', 'room_bookings'],
            };

            return [(int) $site->id => collect($keys)->mapWithKeys(fn ($key) => [$key => (int) $site->{$key}])->all()];
        })->all();
    }

    /* ------------------------------------------------------------------ */
    /*  One Site's report                                                 */
    /* ------------------------------------------------------------------ */

    public function perSiteDetail(Request $request, Site $site)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $user = $request->user();
        $this->reports->assertCanReportOn($user, $site);
        $filters = SiteReportFilters::fromRequest($request);
        $today = $filters->todayDate();

        $report = $this->reports->report([(int) $site->id], $filters);
        $metrics = $report['sites'][(int) $site->id] ?? SiteReportService::emptyMetrics();

        $openHazards = SiteHazard::query()
            ->where('site_id', $site->id)
            ->whereIn('status', SiteReportService::OPEN_HAZARD_STATUSES)
            ->with('assignedTo:id,name')
            ->orderByRaw('CASE WHEN due_date IS NOT NULL AND due_date < ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->limit(50)
            ->get()
            ->map(fn (SiteHazard $hazard) => $this->hazardRow($hazard, $today))
            ->values();

        $templateNames = SiteChecklistTemplate::query()->pluck('name', 'id');
        $checkIssues = SiteChecklistRun::query()
            ->where('site_id', $site->id)
            ->whereBetween('scheduled_date', [$filters->fromDate(), $filters->dueUntil()])
            ->where('status', '!=', 'skipped')
            ->orderByDesc('scheduled_date')
            ->get(['id', 'template_id', 'scheduled_date', 'status', 'completed_at', 'items_failed'])
            ->map(function (SiteChecklistRun $run) use ($today, $templateNames) {
                $due = $run->scheduled_date?->toDateString();
                $doneOn = $run->completed_at
                    ? $run->completed_at->copy()->setTimezone(SiteReportFilters::timezone())->toDateString()
                    : null;
                $outcome = $due ? SiteReportService::checkOutcome($due, $run->status === 'completed', $doneOn, $today) : 'pending';

                return [
                    'id' => $run->id,
                    'name' => $templateNames[$run->template_id] ?? 'Checklist',
                    'due_date' => $due,
                    'done_on' => $doneOn,
                    'outcome' => $outcome,
                    'items_failed' => (int) $run->items_failed,
                ];
            })
            ->filter(fn (array $row) => in_array($row['outcome'], ['late', 'missed'], true) || $row['items_failed'] > 0)
            ->take(25)
            ->values();

        $schedules = SiteInspectionSchedule::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->with('assignedTo:id,name')
            ->orderBy('next_due_date')
            ->get();
        $lastRecords = SiteInspectionRecord::query()
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->get(['id', 'schedule_id', 'completed_at', 'result'])
            ->unique('schedule_id')
            ->keyBy('schedule_id');
        $inspections = $schedules->map(function (SiteInspectionSchedule $schedule) use ($lastRecords, $today) {
            $last = $lastRecords->get($schedule->id);
            $due = $schedule->next_due_date?->toDateString();

            return [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'inspection_type' => $schedule->inspection_type,
                'frequency' => $schedule->frequency,
                'next_due_date' => $due,
                'days_overdue' => $due && $due < $today ? $this->daysBetween($due, $today) : 0,
                'assigned_to' => $schedule->assignedTo?->name,
                'last_done_at' => $last?->completed_at?->toIso8601String(),
                'last_result' => $last?->result,
            ];
        })->values();

        $assets = Asset::query()
            ->where('site_id', $site->id)
            ->whereNotIn('status', SiteReportService::RETIRED_ASSET_STATUSES)
            ->where(fn ($q) => $q
                ->whereIn('status', SiteReportService::OUT_OF_SERVICE_STATUSES)
                ->orWhere('maintenance_due_at', '<', $today))
            ->orderBy('maintenance_due_at')
            ->limit(50)
            ->get(['id', 'name', 'asset_tag', 'category', 'status', 'maintenance_due_at'])
            ->map(fn (Asset $asset) => $this->assetRow($asset, $today))
            ->values();

        $maintenanceDone = AssetMaintenanceLog::query()
            ->whereHas('asset', fn ($q) => $q->where('site_id', $site->id))
            ->whereBetween('performed_at', [$filters->fromUtc(), $filters->toUtc()])
            ->with('asset:id,name,asset_tag')
            ->orderByDesc('performed_at')
            ->limit(25)
            ->get(['id', 'asset_id', 'performed_at', 'type', 'vendor', 'cost'])
            ->map(fn (AssetMaintenanceLog $log) => [
                'id' => $log->id,
                'asset_id' => $log->asset_id,
                'asset_name' => $log->asset?->name,
                'type' => $log->type,
                'vendor' => $log->vendor,
                'performed_at' => $log->performed_at?->toIso8601String(),
                'cost' => $log->cost !== null ? (float) $log->cost : null,
            ])
            ->values();

        $extras = $this->packExtras($this->reports->packKeyForType($site->type), [(int) $site->id], $filters);

        return inertia('sites/reports/site-detail', [
            ...$this->scopeProps($request, $filters, collect([$site])),
            'site' => [
                ...$this->reports->siteRow($site),
                'address' => $site->address !== '' ? $site->address : null,
            ],
            'metrics' => $metrics,
            'extras' => $extras[(int) $site->id] ?? [],
            'trend' => $report['trend'],
            'openHazards' => $openHazards,
            'checkIssues' => $checkIssues,
            'inspections' => $inspections,
            'assets' => $assets,
            'maintenanceDone' => $maintenanceDone,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Overdue corrective actions                                        */
    /* ------------------------------------------------------------------ */

    public function overdueCorrectiveActions(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $user = $request->user();
        $filters = SiteReportFilters::fromRequest($request);
        $today = $filters->todayDate();

        $accessible = $this->reports->accessibleSites($user);
        $sites = $this->reports->inRegion($accessible, $filters->region);
        $ids = $sites->pluck('id')->all();
        if ($request->filled('site_id')) {
            $ids = array_values(array_intersect($ids, [(int) $request->query('site_id')]));
        }

        $base = SiteHazard::query()
            ->whereIn('site_id', $ids === [] ? [0] : $ids)
            ->whereIn('status', SiteReportService::OPEN_HAZARD_STATUSES)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today);

        // Severity is the page's rail view: every severity is sent so each tab
        // can show its count; the address only says which tab opens first.
        $severity = in_array($request->query('severity'), ['low', 'medium', 'high', 'critical'], true)
            ? (string) $request->query('severity')
            : null;
        if ($request->filled('assigned_to')) {
            $base->where('assigned_to_user_id', (int) $request->query('assigned_to'));
        }

        $older = (clone $base)->where('due_date', '<', $filters->fromDate());
        $olderCount = (clone $older)->count();
        $oldestDue = $olderCount > 0 ? substr((string) (clone $older)->min('due_date'), 0, 10) : null;

        $actions = (clone $base)
            ->where('due_date', '>=', $filters->fromDate())
            ->with(['site:id,name,type,region,city,suburb', 'assignedTo:id,name'])
            ->withCount('actions')
            ->orderBy('due_date')
            ->limit(500)
            ->get()
            ->map(fn (SiteHazard $hazard) => $this->hazardRow($hazard, $today))
            ->values();

        return inertia('sites/reports/overdue-actions', [
            ...$this->scopeProps($request, $filters, $accessible),
            'overdueActions' => $actions,
            'older' => ['count' => $olderCount, 'oldest_due' => $oldestDue],
            'severity' => $severity,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Checklist trends                                                   */
    /* ------------------------------------------------------------------ */

    public function checklistTrends(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $user = $request->user();
        $filters = SiteReportFilters::fromRequest($request);
        $today = $filters->todayDate();

        $accessible = $this->reports->accessibleSites($user);
        $sites = $this->reports->inRegion($accessible, $filters->region);
        $ids = $sites->pluck('id')->all();
        $report = $this->reports->report($ids, $filters);

        $failedItems = collect();
        $failuresTrend = [];
        $byTemplate = collect();

        if ($ids !== []) {
            $failedItems = DB::table('site_checklist_responses')
                ->join('site_checklist_runs', 'site_checklist_responses.run_id', '=', 'site_checklist_runs.id')
                ->join('site_checklist_template_items', 'site_checklist_responses.template_item_id', '=', 'site_checklist_template_items.id')
                ->join('site_checklist_templates', 'site_checklist_runs.template_id', '=', 'site_checklist_templates.id')
                ->where('site_checklist_responses.is_failed', true)
                ->whereIn('site_checklist_runs.site_id', $ids)
                ->whereBetween('site_checklist_runs.scheduled_date', [$filters->fromDate(), $filters->toDate()])
                ->select([
                    'site_checklist_runs.template_id',
                    'site_checklist_templates.name as template_name',
                    'site_checklist_template_items.question as item_text',
                    DB::raw('COUNT(*) as failure_count'),
                    DB::raw('COUNT(DISTINCT site_checklist_runs.site_id) as site_count'),
                    DB::raw('MAX(site_checklist_runs.scheduled_date) as last_failed_on'),
                ])
                ->groupBy('site_checklist_runs.template_id', 'site_checklist_templates.name', 'site_checklist_template_items.question')
                ->orderByDesc('failure_count')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'template_id' => (int) $row->template_id,
                    'template_name' => $row->template_name,
                    'item_text' => $row->item_text,
                    'failure_count' => (int) $row->failure_count,
                    'site_count' => (int) $row->site_count,
                    'last_failed_on' => $row->last_failed_on ? substr((string) $row->last_failed_on, 0, 10) : null,
                ]);

            $failuresByDay = DB::table('site_checklist_responses')
                ->join('site_checklist_runs', 'site_checklist_responses.run_id', '=', 'site_checklist_runs.id')
                ->where('site_checklist_responses.is_failed', true)
                ->whereIn('site_checklist_runs.site_id', $ids)
                ->whereBetween('site_checklist_runs.scheduled_date', [$filters->fromDate(), $filters->toDate()])
                ->groupBy('site_checklist_runs.scheduled_date')
                ->select(['site_checklist_runs.scheduled_date as day', DB::raw('COUNT(*) as failures')])
                ->get();
            foreach ($failuresByDay as $row) {
                $key = $filters->bucketKey(substr((string) $row->day, 0, 10));
                $failuresTrend[$key] = ($failuresTrend[$key] ?? 0) + (int) $row->failures;
            }

            $templateNames = SiteChecklistTemplate::query()->pluck('name', 'id');
            $byTemplate = SiteChecklistRun::query()
                ->whereIn('site_id', $ids)
                ->whereBetween('scheduled_date', [$filters->fromDate(), $filters->dueUntil()])
                ->where('status', '!=', 'skipped')
                ->toBase()
                ->get(['template_id', 'site_id', 'scheduled_date', 'status', 'completed_at'])
                ->groupBy('template_id')
                ->map(function (Collection $runs, $templateId) use ($today, $templateNames) {
                    $counts = ['due' => 0, 'on_time' => 0, 'late' => 0, 'missed' => 0];
                    foreach ($runs as $run) {
                        $outcome = SiteReportService::checkOutcome(
                            substr((string) $run->scheduled_date, 0, 10),
                            $run->status === 'completed',
                            SiteReportFilters::localDate($run->completed_at),
                            $today,
                        );
                        if ($outcome === 'pending') {
                            continue;
                        }
                        $counts['due']++;
                        $counts[$outcome]++;
                    }

                    return [
                        'template_id' => (int) $templateId,
                        'name' => $templateNames[$templateId] ?? 'Checklist',
                        'site_count' => $runs->pluck('site_id')->unique()->count(),
                        ...$counts,
                        'on_time_rate' => SiteReportService::rate($counts['on_time'], $counts['due']),
                    ];
                })
                ->filter(fn (array $row) => $row['due'] > 0)
                ->sortBy(fn (array $row) => $row['on_time_rate'] ?? 101)
                ->values();
        }

        $trend = array_map(fn (array $bucket) => [
            ...$bucket,
            'failures' => $failuresTrend[$bucket['key']] ?? 0,
        ], $report['trend']);

        return inertia('sites/reports/checklist-trends', [
            ...$this->scopeProps($request, $filters, $accessible),
            'totals' => $report['totals'],
            'trend' => $trend,
            'failedItems' => $failedItems->values(),
            'byTemplate' => $byTemplate,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Inspections & maintenance                                          */
    /* ------------------------------------------------------------------ */

    public function inspectionsMaintenance(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $user = $request->user();
        $filters = SiteReportFilters::fromRequest($request);
        $today = $filters->todayDate();

        $accessible = $this->reports->accessibleSites($user);
        $sites = $this->reports->inRegion($accessible, $filters->region);
        $ids = $sites->pluck('id')->all();
        $report = $this->reports->report($ids, $filters);
        $siteById = $sites->keyBy('id');

        $overdueInspections = collect();
        $failedInspections = collect();
        $overdueMaintenance = collect();
        $slippedMaintenance = collect();

        if ($ids !== []) {
            $overdueInspections = SiteInspectionSchedule::query()
                ->whereIn('site_id', $ids)
                ->where('is_active', true)
                ->whereNotNull('next_due_date')
                ->where('next_due_date', '<', $today)
                ->with('assignedTo:id,name')
                ->orderBy('next_due_date')
                ->limit(200)
                ->get()
                ->map(fn (SiteInspectionSchedule $schedule) => [
                    'id' => $schedule->id,
                    'title' => $schedule->title,
                    'inspection_type' => $schedule->inspection_type,
                    'next_due_date' => $schedule->next_due_date?->toDateString(),
                    'days_overdue' => $this->daysBetween($schedule->next_due_date?->toDateString(), $today),
                    'assigned_to' => $schedule->assignedTo?->name,
                    'site' => $this->siteRef($siteById->get($schedule->site_id)),
                ])
                ->values();

            $failedInspections = SiteInspectionRecord::query()
                ->whereIn('site_id', $ids)
                ->where('result', 'fail')
                ->whereBetween('completed_at', [$filters->fromUtc(), $filters->toUtc()])
                ->with('schedule:id,title,inspection_type')
                ->orderByDesc('completed_at')
                ->limit(50)
                ->get()
                ->map(fn (SiteInspectionRecord $record) => [
                    'id' => $record->id,
                    'title' => $record->schedule?->title ?? 'Inspection',
                    'inspection_type' => $record->schedule?->inspection_type,
                    'completed_at' => $record->completed_at?->toIso8601String(),
                    'findings' => $record->findings ? Str::limit((string) $record->findings, 160) : null,
                    'site' => $this->siteRef($siteById->get($record->site_id)),
                ])
                ->values();

            $overdueMaintenance = Asset::query()
                ->whereIn('site_id', $ids)
                ->whereNotIn('status', SiteReportService::RETIRED_ASSET_STATUSES)
                ->whereNotNull('maintenance_due_at')
                ->where('maintenance_due_at', '<', $today)
                ->orderBy('maintenance_due_at')
                ->limit(200)
                ->get(['id', 'site_id', 'name', 'asset_tag', 'category', 'status', 'maintenance_due_at'])
                ->map(fn (Asset $asset) => [
                    ...$this->assetRow($asset, $today),
                    'site' => $this->siteRef($siteById->get($asset->site_id)),
                ])
                ->values();

            $assets = Asset::query()
                ->whereIn('site_id', $ids)
                ->whereNotIn('status', SiteReportService::RETIRED_ASSET_STATUSES)
                ->get(['id', 'site_id', 'name', 'asset_tag'])
                ->keyBy('id');
            $slippedMaintenance = collect($this->reports->maintenanceOutcomes($assets->keys()->all(), $filters))
                ->filter(fn (array $outcome) => in_array($outcome['kind'], ['late', 'missed'], true))
                ->sortBy('date')
                ->take(100)
                ->map(function (array $outcome) use ($assets, $siteById) {
                    $asset = $assets->get($outcome['asset_id']);

                    return [
                        'id' => $outcome['log_id'],
                        'asset_id' => $outcome['asset_id'],
                        'asset_name' => $asset?->name,
                        'asset_tag' => $asset?->asset_tag,
                        'due_date' => $outcome['date'],
                        'outcome' => $outcome['kind'],
                        'site' => $this->siteRef($siteById->get($asset?->site_id)),
                    ];
                })
                ->values();
        }

        return inertia('sites/reports/inspections-maintenance', [
            ...$this->scopeProps($request, $filters, $accessible),
            'totals' => $report['totals'],
            'trend' => $report['trend'],
            'overdueInspections' => $overdueInspections,
            'failedInspections' => $failedInspections,
            'overdueMaintenance' => $overdueMaintenance,
            'slippedMaintenance' => $slippedMaintenance,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Asset condition                                                    */
    /* ------------------------------------------------------------------ */

    public function assetConditionReport(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $user = $request->user();
        $filters = SiteReportFilters::fromRequest($request);
        $today = $filters->todayDate();
        $soon = $filters->today->addDays(30)->toDateString();

        $accessible = $this->reports->accessibleSites($user);
        $sites = $this->reports->inRegion($accessible, $filters->region);
        $ids = $sites->pluck('id')->all();
        if ($request->filled('site_id')) {
            $ids = array_values(array_intersect($ids, [(int) $request->query('site_id')]));
        }
        $report = $this->reports->report($ids, $filters);
        $siteById = $sites->keyBy('id');

        $assets = $ids === [] ? collect() : Asset::query()
            ->whereIn('site_id', $ids)
            ->get(['id', 'site_id', 'name', 'asset_tag', 'category', 'status', 'maintenance_due_at', 'inspection_due_at', 'warranty_expires_at']);

        $byStatus = $assets->groupBy(fn (Asset $asset) => (string) ($asset->status ?: 'unknown'))
            ->map(fn (Collection $group, string $status) => ['status' => $status, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values();

        $inUse = $assets->reject(fn (Asset $asset) => in_array($asset->status, SiteReportService::RETIRED_ASSET_STATUSES, true));
        $flagsFor = function (Asset $asset) use ($today, $soon): array {
            $maintenance = $asset->maintenance_due_at?->toDateString();
            $inspection = $asset->inspection_due_at?->toDateString();
            $warranty = $asset->warranty_expires_at?->toDateString();

            return array_values(array_filter([
                in_array($asset->status, SiteReportService::OUT_OF_SERVICE_STATUSES, true) ? 'out_of_service' : null,
                $maintenance && $maintenance < $today ? 'maintenance_overdue' : null,
                $inspection && $inspection < $today ? 'inspection_overdue' : null,
                $warranty && $warranty < $today ? 'warranty_expired' : null,
                $warranty && $warranty >= $today && $warranty <= $soon ? 'warranty_expiring' : null,
            ]));
        };

        $rows = $inUse
            ->map(fn (Asset $asset) => [
                ...$this->assetRow($asset, $today),
                'inspection_due_at' => $asset->inspection_due_at?->toDateString(),
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
                'flags' => $flagsFor($asset),
                'site' => $this->siteRef($siteById->get($asset->site_id)),
            ])
            ->filter(fn (array $row) => $row['flags'] !== [])
            ->sortBy(fn (array $row) => [count($row['flags']) * -1, $row['maintenance_due_at'] ?? '9999-12-31'])
            ->take(500)
            ->values();

        $countFlag = fn (string $flag) => $inUse->filter(fn (Asset $asset) => in_array($flag, $flagsFor($asset), true))->count();

        return inertia('sites/reports/asset-condition', [
            ...$this->scopeProps($request, $filters, $accessible),
            'summary' => [
                'total_assets' => $inUse->count(),
                'retired' => $assets->count() - $inUse->count(),
                'needs_attention' => $inUse->filter(fn (Asset $asset) => $flagsFor($asset) !== [])->count(),
                'out_of_service' => $countFlag('out_of_service'),
                'maintenance_overdue' => $countFlag('maintenance_overdue'),
                'inspection_overdue' => $countFlag('inspection_overdue'),
                'warranty_expired' => $countFlag('warranty_expired'),
                'warranty_expiring_soon' => $countFlag('warranty_expiring'),
                'maintenance_done' => $report['totals']['maintenance_done'],
                'maintenance_spend' => $report['totals']['maintenance_spend'],
            ],
            'byStatus' => $byStatus,
            'trend' => $report['trend'],
            'assets' => $rows,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Exports                                                            */
    /* ------------------------------------------------------------------ */

    public function export(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.export'), 403);

        $format = $request->input('format', 'csv');
        if ($format !== 'csv') {
            return response()->json([
                'message' => 'Only CSV export is currently available.',
            ], 422);
        }

        $type = $request->input('type', 'houses');
        $pack = match (is_string($type) ? $type : 'houses') {
            'facilities' => 'facilities',
            'head_office', 'head-office' => 'head-office',
            'all' => null,
            default => 'houses',
        };
        abort_if($pack !== null && ! $this->reports->canSeePack($request->user(), $pack), 403);

        $filters = SiteReportFilters::fromRequest($request);
        $sites = $this->reports->scopedSites(
            $request->user(),
            $filters,
            $pack === null ? null : SiteReportService::PACKS[$pack]['types'],
        );
        $report = $this->reports->report($sites->pluck('id')->all(), $filters);

        $rows = $sites->map(function (Site $site) use ($report, $filters) {
            $m = $report['sites'][$site->id] ?? SiteReportService::emptyMetrics();

            return [
                'site_name' => $site->name,
                'site_type' => SiteReportService::typeLabel($site->type),
                'region' => (string) $site->resolved_region,
                'period_from' => $filters->fromDate(),
                'period_to' => $filters->toDate(),
                'hazards_open' => $m['hazards_open'],
                'hazards_high_or_critical' => $m['hazards_serious'],
                'corrective_actions_overdue' => $m['actions_overdue'],
                'hazards_raised' => $m['hazards_raised'],
                'hazards_closed' => $m['hazards_closed'],
                'checks_due' => $m['checks_due'],
                'checks_on_time' => $m['checks_on_time'],
                'checks_late' => $m['checks_late'],
                'checks_missed' => $m['checks_missed'],
                'checks_on_time_percent' => $m['checks_on_time_rate'] ?? '',
                'inspections_overdue_now' => $m['inspections_overdue_now'],
                'assets_in_use' => $m['assets'],
                'assets_out_of_service' => $m['assets_out_of_service'],
                'maintenance_overdue_now' => $m['maintenance_overdue_now'],
                'maintenance_on_time_percent' => $m['maintenance_on_time_rate'] ?? '',
                'maintenance_done' => $m['maintenance_done'],
                'maintenance_spend' => number_format((float) $m['maintenance_spend'], 2, '.', ''),
            ];
        })->all();

        $headers = array_keys($rows[0] ?? ['site_name' => null, 'site_type' => null, 'region' => null, 'period_from' => null, 'period_to' => null]);
        $filename = sprintf('sites-report-%s-%s.csv', $pack ?? 'all', now()->format('Ymd-His'));

        $csv = implode(',', $headers).PHP_EOL;
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(function ($value) {
                $sanitized = $this->sanitizeCsvValue((string) $value);
                $escaped = str_replace('"', '""', $sanitized);

                return "\"{$escaped}\"";
            }, $row)).PHP_EOL;
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function vendorContactsExport(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canDo('reports.sites.export'), 403);

        $siteIds = $this->reports->accessibleSites($request->user())->pluck('id')->all();

        $vendors = SiteVendor::with('site:id,name,type,region')
            ->where('is_active', true)
            ->whereIn('site_id', $siteIds === [] ? [0] : $siteIds)
            ->orderBy('company_name')
            ->get();

        $headers = [
            'Site Name', 'Site Type', 'Region', 'Service Type', 'Company Name',
            'Contact Name', 'Phone', 'After Hours Phone', 'Email',
            'Account Number', 'Preferred', 'Contact Method',
        ];

        $filename = sprintf('vendor-contacts-%s.csv', now()->format('Ymd-His'));

        return new StreamedResponse(function () use ($vendors, $headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($vendors as $vendor) {
                fputcsv($handle, [
                    $this->sanitizeCsvValue($vendor->site?->name),
                    $this->sanitizeCsvValue($vendor->site?->display_type),
                    $this->sanitizeCsvValue($vendor->site?->region),
                    $this->sanitizeCsvValue($vendor->service_type),
                    $this->sanitizeCsvValue($vendor->company_name),
                    $this->sanitizeCsvValue($vendor->contact_name),
                    $this->sanitizeCsvValue($vendor->phone),
                    $this->sanitizeCsvValue($vendor->after_hours_phone),
                    $this->sanitizeCsvValue($vendor->email),
                    $this->sanitizeCsvValue($vendor->account_number),
                    $vendor->is_preferred ? 'Yes' : 'No',
                    $this->sanitizeCsvValue($vendor->preferred_contact_method),
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The shared scope every report page gets: the filters as read, the
     * period choices, the regions of the viewer's Sites and export rights.
     *
     * @param  Collection<int, Site>  $accessible
     * @return array<string, mixed>
     */
    private function scopeProps(Request $request, SiteReportFilters $filters, Collection $accessible): array
    {
        return [
            'filters' => $filters->toArray(),
            'periods' => SiteReportFilters::periodOptions(),
            'regions' => $this->reports->regions($accessible),
            'can' => [
                'export' => (bool) $request->user()?->canDo('reports.sites.export'),
            ],
        ];
    }

    /**
     * @param  array<string, int|float|null>  $metrics
     * @return array<string, int|float|null>
     */
    private function pickRowMetrics(array $metrics): array
    {
        return array_intersect_key($metrics, array_flip(self::SITE_ROW_METRICS));
    }

    /** @return array{id: int, name: string, type: string, pack: string}|null */
    private function siteRef(?Site $site): ?array
    {
        if (! $site) {
            return null;
        }

        return [
            'id' => (int) $site->id,
            'name' => (string) $site->name,
            'type' => (string) $site->type,
            'pack' => $this->reports->packKeyForType($site->type),
        ];
    }

    /** @return array<string, mixed> */
    private function hazardRow(SiteHazard $hazard, string $today): array
    {
        $due = $hazard->due_date?->toDateString();

        return [
            'id' => $hazard->id,
            'reference_number' => $hazard->reference_number,
            'description' => Str::limit((string) $hazard->description, 160),
            'hazard_type' => $hazard->hazard_type,
            'severity' => $hazard->severity,
            'risk_rating' => $hazard->risk_rating,
            'status' => $hazard->status,
            'due_date' => $due,
            'days_overdue' => $due && $due < $today ? $this->daysBetween($due, $today) : 0,
            'assigned_to' => $hazard->assignedTo?->name,
            'actions_count' => (int) ($hazard->actions_count ?? 0),
            'site' => $hazard->relationLoaded('site') ? $this->siteRef($hazard->site) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function assetRow(Asset $asset, string $today): array
    {
        $due = $asset->maintenance_due_at?->toDateString();

        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'category' => $asset->category,
            'status' => $asset->status,
            'maintenance_due_at' => $due,
            'days_overdue' => $due && $due < $today ? $this->daysBetween($due, $today) : 0,
        ];
    }

    private function daysBetween(?string $from, string $to): int
    {
        if (! $from) {
            return 0;
        }

        return max(0, (int) round((strtotime($to.' 00:00:00 UTC') - strtotime($from.' 00:00:00 UTC')) / 86400));
    }

    /**
     * Sanitize a value for CSV export to prevent formula injection.
     * Prefixes dangerous characters (=, +, -, @, tab, carriage return) with a single quote.
     */
    private function sanitizeCsvValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $dangerousChars = ['=', '+', '-', '@', "\t", "\r"];
        if (in_array($value[0], $dangerousChars, true)) {
            return "'".$value;
        }

        return $value;
    }
}
