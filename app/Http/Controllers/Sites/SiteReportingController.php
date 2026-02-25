<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteChecklistResponse;
use App\Models\SiteHazard;
use App\Models\SiteVendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;

class SiteReportingController extends Controller
{
    use ResolvesAllowedSiteTypes;
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        $allowedSiteTypes = $this->allowedSiteTypes($request);

        $sites = Site::active()
            ->whereIn('type', $allowedSiteTypes)
            ->select(['id', 'name', 'type', 'region'])
            ->orderBy('name')
            ->get();

        return inertia('sites/reports/index', [
            'sites' => $sites,
        ]);
    }

    public function houses(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        abort_unless(in_array('house', $this->allowedSiteTypes($request), true), 403);

        $dateFrom = Carbon::parse($request->input('date_from', now()->subMonths(3)));
        $dateTo = Carbon::parse($request->input('date_to', now()));

        $houses = Site::where('type', 'house')
            ->when($request->region, fn($q) => $q->where('region', $request->region))
            ->withCount([
                'houseRooms' => fn($q) => $q->active(),
                'clients' => fn($q) => $q->where('status', 'active'),
            ])
            ->with([
                'hazards' => fn($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]),
                'checklistRuns' => fn($q) => $q->whereBetween('completed_at', [$dateFrom, $dateTo]),
            ])
            ->get();

        $hazardAvgCloseDays = $this->calculateHazardAvgCloseDays($houses);

        $stats = [
            'total_houses' => $houses->count(),
            'total_bedrooms' => $houses->sum('house_rooms_count'),
            'total_clients' => $houses->sum('clients_count'),
            'open_hazards' => $houses->sum(fn($h) => $h->hazards->where('status', 'open')->count()),
            'critical_hazards' => $houses->sum(fn($h) => $h->hazards->where('status', 'open')->where('severity', 'critical')->count()),
            'checklist_completion_rate' => $this->calculateChecklistCompletionRate($houses, $dateFrom, $dateTo),
            'hazard_avg_close_days' => $hazardAvgCloseDays,
        ];

        return inertia('sites/reports/houses', [
            'houses' => $houses,
            'stats' => $stats,
            'dateRange' => ['from' => $dateFrom->toDateString(), 'to' => $dateTo->toDateString()],
            'regions' => Site::distinct()->pluck('region')->filter()->values(),
        ]);
    }

    public function facilities(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        abort_unless(in_array('facility', $this->allowedSiteTypes($request), true), 403);

        $dateFrom = Carbon::parse($request->input('date_from', now()->subMonths(3)));
        $dateTo = Carbon::parse($request->input('date_to', now()));

        $facilities = Site::where('type', 'facility')
            ->withCount([
                'facilityZones' => fn($q) => $q->active(),
                'assets',
            ])
            ->with([
                'hazards' => fn($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]),
                'checklistRuns' => fn($q) => $q->whereBetween('completed_at', [$dateFrom, $dateTo]),
                'inspectionRecords' => fn($q) => $q->whereBetween('completed_at', [$dateFrom, $dateTo]),
            ])
            ->get();

        $hazardAvgCloseDays = $this->calculateHazardAvgCloseDays($facilities);

        $stats = [
            'total_facilities' => $facilities->count(),
            'total_zones' => $facilities->sum('facility_zones_count'),
            'total_assets' => $facilities->sum('assets_count'),
            'open_hazards' => $facilities->sum(fn($f) => $f->hazards->where('status', 'open')->count()),
            'equipment_failures' => $facilities->sum(fn($f) => $f->hazards->where('hazard_type', 'equipment')->count()),
            'safety_walkthrough_completion' => $this->calculateWalkthroughCompletion($facilities, $dateFrom, $dateTo),
            'hazard_avg_close_days' => $hazardAvgCloseDays,
        ];

        return inertia('sites/reports/facilities', [
            'facilities' => $facilities,
            'stats' => $stats,
            'dateRange' => ['from' => $dateFrom->toDateString(), 'to' => $dateTo->toDateString()],
        ]);
    }

    public function headOffice(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);
        abort_unless(in_array('head_office', $this->allowedSiteTypes($request), true), 403);

        $dateFrom = Carbon::parse($request->input('date_from', now()->subMonths(3)));
        $dateTo = Carbon::parse($request->input('date_to', now()));

        $offices = Site::where('type', 'head_office')
            ->withCount([
                'hoResources' => fn($q) => $q->active(),
            ])
            ->with([
                'hazards' => fn($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]),
                'checklistRuns' => fn($q) => $q->whereBetween('completed_at', [$dateFrom, $dateTo]),
                'calendarEvents' => fn($q) => $q->whereBetween('start_at', [$dateFrom, $dateTo])->where('event_type', 'room_booking'),
            ])
            ->get();

        $hazardAvgCloseDays = $this->calculateHazardAvgCloseDays($offices);

        $stats = [
            'total_offices' => $offices->count(),
            'total_rooms' => $offices->sum('ho_resources_count'),
            'room_bookings' => $offices->sum(fn($o) => $o->calendarEvents->count()),
            'open_hazards' => $offices->sum(fn($o) => $o->hazards->where('status', 'open')->count()),
            'safety_compliance_rate' => $this->calculateSafetyCompliance($offices, $dateFrom, $dateTo),
            'hazard_avg_close_days' => $hazardAvgCloseDays,
        ];

        return inertia('sites/reports/head-office', [
            'offices' => $offices,
            'stats' => $stats,
            'dateRange' => ['from' => $dateFrom->toDateString(), 'to' => $dateTo->toDateString()],
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.export'), 403);

        $type = $request->input('type', 'houses');
        $format = $request->input('format', 'csv');
        $dateFrom = Carbon::parse($request->input('date_from', now()->subMonths(3)));
        $dateTo = Carbon::parse($request->input('date_to', now()));

        if ($format !== 'csv') {
            return response()->json([
                'message' => 'Only CSV export is currently available.',
            ], 422);
        }

        $rows = $this->buildExportRows($type, $dateFrom, $dateTo);
        $headers = array_keys($rows[0] ?? ['site_name' => null, 'site_type' => null, 'period_from' => null, 'period_to' => null]);
        $filename = sprintf('sites-report-%s-%s.csv', $type, now()->format('Ymd-His'));

        $csv = implode(',', $headers) . PHP_EOL;
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(function ($value) {
                $sanitized = $this->sanitizeCsvValue((string) $value);
                $escaped = str_replace('"', '""', $sanitized);
                return "\"{$escaped}\"";
            }, $row)) . PHP_EOL;
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function perSiteDetail(Request $request, Site $site)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);

        $site->load([
            'hazards',
            'checklistRuns.template',
            'inspectionSchedules',
            'inspectionRecords',
            'credentials',
            'vendors',
            'assets',
        ]);

        // Hazard stats
        $allHazards = $site->hazards;
        $openHazards = $allHazards->whereIn('status', ['open', 'in_progress'])->count();
        $closedHazards = $allHazards->whereIn('status', ['mitigated', 'closed'])->count();
        $overdueHazards = $allHazards->filter(fn($h) => $h->isOverdue())->count();
        $avgTimeToClose = $allHazards
            ->where('status', 'closed')
            ->filter(fn($h) => $h->closed_at !== null)
            ->avg(fn($h) => $h->closed_at->diffInDays($h->created_at));

        // Checklist stats
        $allRuns = $site->checklistRuns;
        $totalRuns = $allRuns->count();
        $completedRuns = $allRuns->where('status', 'completed')->count();
        $overdueRuns = $allRuns->filter(fn($r) => $r->isOverdue())->count();
        $checklistCompletionRate = $totalRuns > 0 ? round(($completedRuns / $totalRuns) * 100, 1) : 0;

        // Inspection stats
        $inspectionSchedules = $site->inspectionSchedules;
        $scheduledInspections = $inspectionSchedules->where('is_active', true)->count();
        $completedInspections = $site->inspectionRecords->count();
        $overdueInspections = $inspectionSchedules
            ->filter(fn($s) => $s->is_active && $s->isDue())
            ->count();

        // Credential expiry status
        $credentials = $site->credentials;
        $credentialsRequiringReauth = $credentials->where('requires_reauth', true)->count();

        // Recent audit log entries
        $recentAuditEntries = collect();
        try {
            $recentAuditEntries = DB::table('audit_logs')
                ->where(function ($q) use ($site) {
                    $q->where(function ($sub) use ($site) {
                        $sub->where('auditable_type', 'App\\Models\\SiteHazard')
                            ->whereIn('auditable_id', $site->hazards->pluck('id'));
                    })->orWhere(function ($sub) use ($site) {
                        $sub->where('auditable_type', 'App\\Models\\SiteChecklistRun')
                            ->whereIn('auditable_id', $site->checklistRuns->pluck('id'));
                    });
                })
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (\Exception $e) {
            // audit_logs table may not exist
        }

        return inertia('sites/reports/site-detail', [
            'site' => $site,
            'hazardStats' => [
                'open' => $openHazards,
                'closed' => $closedHazards,
                'overdue' => $overdueHazards,
                'avg_time_to_close' => $avgTimeToClose !== null ? round($avgTimeToClose, 1) : null,
            ],
            'checklistStats' => [
                'total_runs' => $totalRuns,
                'completed_runs' => $completedRuns,
                'overdue_runs' => $overdueRuns,
                'completion_rate' => $checklistCompletionRate,
            ],
            'inspectionStats' => [
                'scheduled' => $scheduledInspections,
                'completed' => $completedInspections,
                'overdue' => $overdueInspections,
            ],
            'credentialStats' => [
                'total' => $credentials->count(),
                'requiring_reauth' => $credentialsRequiringReauth,
            ],
            'recentAuditEntries' => $recentAuditEntries,
        ]);
    }

    public function overdueCorrectiveActions(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);

        $query = SiteHazard::where('status', 'open')
            ->where('due_date', '<', now())
            ->with(['site:id,name,type,region', 'assignedTo:id,name', 'actions']);

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->input('site_id'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_user_id', $request->input('assigned_to'));
        }

        $overdueActions = $query->orderBy('due_date')->get();
        $sites = Site::active()->select(['id', 'name'])->orderBy('name')->get();

        return inertia('sites/reports/overdue-actions', [
            'overdueActions' => $overdueActions,
            'sites' => $sites,
            'filters' => $request->only(['site_id', 'severity', 'assigned_to']),
        ]);
    }

    public function checklistTrends(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);

        $threeMonthsAgo = now()->subMonths(3);

        $failedItems = SiteChecklistResponse::where('is_failed', true)
            ->where('site_checklist_responses.created_at', '>=', $threeMonthsAgo)
            ->join('site_checklist_template_items', 'site_checklist_responses.template_item_id', '=', 'site_checklist_template_items.id')
            ->join('site_checklist_runs', 'site_checklist_responses.run_id', '=', 'site_checklist_runs.id')
            ->join('site_checklist_templates', 'site_checklist_runs.template_id', '=', 'site_checklist_templates.id')
            ->select([
                'site_checklist_runs.template_id',
                'site_checklist_templates.name as template_name',
                'site_checklist_template_items.question as item_text',
                DB::raw('COUNT(*) as failure_count'),
                DB::raw('MIN(site_checklist_responses.created_at) as first_failure'),
                DB::raw('MAX(site_checklist_responses.created_at) as last_failure'),
            ])
            ->groupBy('site_checklist_runs.template_id', 'site_checklist_templates.name', 'site_checklist_template_items.question')
            ->orderByDesc('failure_count')
            ->limit(50)
            ->get();

        return inertia('sites/reports/checklist-trends', [
            'failedItems' => $failedItems,
            'dateRange' => [
                'from' => $threeMonthsAgo->toDateString(),
                'to' => now()->toDateString(),
            ],
        ]);
    }

    public function assetConditionReport(Request $request)
    {
        abort_unless($request->user()?->canDo('reports.sites.view'), 403);

        $query = Asset::with('site:id,name,type');
        if ($request->filled('site_id')) {
            $query->where('site_id', $request->input('site_id'));
        }

        $assets = $query->get();

        $grouped = $assets->groupBy('status')->map(function ($group, $status) {
            return [
                'status' => $status,
                'count' => $group->count(),
                'warranty_expired' => $group->filter(fn($a) => $a->warranty_expires_at && $a->warranty_expires_at->isPast())->count(),
                'warranty_expiring_soon' => $group->filter(fn($a) => $a->warranty_expires_at && $a->warranty_expires_at->isFuture() && $a->warranty_expires_at->diffInDays(now()) <= 30)->count(),
                'assets' => $group->map(fn($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'asset_tag' => $a->asset_tag,
                    'category' => $a->category,
                    'manufacturer' => $a->manufacturer,
                    'model' => $a->model,
                    'status' => $a->status,
                    'warranty_expires_at' => $a->warranty_expires_at?->toDateString(),
                    'site_name' => $a->site?->name,
                ])->values(),
            ];
        })->values();

        $sites = Site::active()->select(['id', 'name'])->orderBy('name')->get();

        return inertia('sites/reports/asset-condition', [
            'conditionGroups' => $grouped,
            'sites' => $sites,
            'filters' => $request->only(['site_id']),
            'summary' => [
                'total_assets' => $assets->count(),
                'warranty_expired' => $assets->filter(fn($a) => $a->warranty_expires_at && $a->warranty_expires_at->isPast())->count(),
                'warranty_expiring_soon' => $assets->filter(fn($a) => $a->warranty_expires_at && $a->warranty_expires_at->isFuture() && $a->warranty_expires_at->diffInDays(now()) <= 30)->count(),
            ],
        ]);
    }

    public function vendorContactsExport(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canDo('reports.sites.export'), 403);

        $vendors = SiteVendor::with('site:id,name,type,region')
            ->where('is_active', true)
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
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Calculate average days to close hazards across a collection of sites.
     */
    private function calculateHazardAvgCloseDays($sites): ?float
    {
        $allClosedHazards = $sites->flatMap(function ($site) {
            return $site->hazards
                ->where('status', 'closed')
                ->filter(fn($h) => $h->closed_at !== null);
        });

        if ($allClosedHazards->isEmpty()) {
            return null;
        }

        return round($allClosedHazards->avg(fn($h) => $h->closed_at->diffInDays($h->created_at)), 1);
    }

    private function calculateChecklistCompletionRate($houses, $dateFrom, $dateTo): float
    {
        $totalRuns = 0;
        $completedRuns = 0;

        foreach ($houses as $house) {
            $runs = $house->checklistRuns
                ->whereBetween('scheduled_date', [$dateFrom, $dateTo]);
            $totalRuns += $runs->count();
            $completedRuns += $runs->where('status', 'completed')->count();
        }

        return $totalRuns > 0 ? round(($completedRuns / $totalRuns) * 100, 1) : 0;
    }

    private function calculateWalkthroughCompletion($facilities, $dateFrom, $dateTo): float
    {
        $totalRuns = 0;
        $completedRuns = 0;

        foreach ($facilities as $facility) {
            $runs = $facility->checklistRuns
                ->whereBetween('scheduled_date', [$dateFrom, $dateTo]);
            $totalRuns += $runs->count();
            $completedRuns += $runs->where('status', 'completed')->count();
        }

        return $totalRuns > 0 ? round(($completedRuns / $totalRuns) * 100, 1) : 0;
    }

    private function calculateSafetyCompliance($offices, $dateFrom, $dateTo): float
    {
        $totalRuns = 0;
        $passedRuns = 0;

        foreach ($offices as $office) {
            $runs = $office->checklistRuns
                ->whereBetween('scheduled_date', [$dateFrom, $dateTo]);
            $totalRuns += $runs->count();
            $passedRuns += $runs->where('status', 'completed')->where('items_failed', 0)->count();
        }

        return $totalRuns > 0 ? round(($passedRuns / $totalRuns) * 100, 1) : 0;
    }

    private function buildExportRows(string $type, Carbon $dateFrom, Carbon $dateTo): array
    {
        $sites = Site::query()
            ->where('type', match ($type) {
                'facilities' => 'facility',
                'head_office' => 'head_office',
                default => 'house',
            })
            ->with([
                'hazards' => fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]),
                'checklistRuns' => fn ($q) => $q->whereBetween('scheduled_date', [$dateFrom, $dateTo]),
                'inspectionRecords' => fn ($q) => $q->whereBetween('due_date', [$dateFrom, $dateTo]),
                'assets',
            ])
            ->orderBy('name')
            ->get();

        return $sites->map(function (Site $site) use ($dateFrom, $dateTo) {
            $checklistRuns = $site->checklistRuns;
            $completedChecklistRuns = $checklistRuns->where('status', 'completed')->count();

            return [
                'site_name' => $site->name,
                'site_type' => $site->type,
                'region' => (string) $site->region,
                'period_from' => $dateFrom->toDateString(),
                'period_to' => $dateTo->toDateString(),
                'hazards_open' => $site->hazards->whereIn('status', ['open', 'in_progress'])->count(),
                'hazards_closed' => $site->hazards->whereIn('status', ['mitigated', 'closed'])->count(),
                'hazards_critical' => $site->hazards->where('severity', 'critical')->count(),
                'checklist_total' => $checklistRuns->count(),
                'checklist_completed' => $completedChecklistRuns,
                'checklist_completion_percent' => $checklistRuns->count() > 0
                    ? round(($completedChecklistRuns / $checklistRuns->count()) * 100, 1)
                    : 0,
                'inspection_records' => $site->inspectionRecords->count(),
                'assets_count' => $site->assets->count(),
            ];
        })->all();
    }

}
