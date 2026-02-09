<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

class SiteReportingController extends Controller
{
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

        $stats = [
            'total_houses' => $houses->count(),
            'total_bedrooms' => $houses->sum('house_rooms_count'),
            'total_clients' => $houses->sum('clients_count'),
            'open_hazards' => $houses->sum(fn($h) => $h->hazards->where('status', 'open')->count()),
            'critical_hazards' => $houses->sum(fn($h) => $h->hazards->where('status', 'open')->where('severity', 'critical')->count()),
            'checklist_completion_rate' => $this->calculateChecklistCompletionRate($houses, $dateFrom, $dateTo),
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

        $stats = [
            'total_facilities' => $facilities->count(),
            'total_zones' => $facilities->sum('facility_zones_count'),
            'total_assets' => $facilities->sum('assets_count'),
            'open_hazards' => $facilities->sum(fn($f) => $f->hazards->where('status', 'open')->count()),
            'equipment_failures' => $facilities->sum(fn($f) => $f->hazards->where('hazard_type', 'equipment')->count()),
            'safety_walkthrough_completion' => $this->calculateWalkthroughCompletion($facilities, $dateFrom, $dateTo),
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

        $stats = [
            'total_offices' => $offices->count(),
            'total_rooms' => $offices->sum('ho_resources_count'),
            'room_bookings' => $offices->sum(fn($o) => $o->calendarEvents->count()),
            'open_hazards' => $offices->sum(fn($o) => $o->hazards->where('status', 'open')->count()),
            'safety_compliance_rate' => $this->calculateSafetyCompliance($offices, $dateFrom, $dateTo),
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
                $escaped = str_replace('"', '""', (string) $value);
                return "\"{$escaped}\"";
            }, $row)) . PHP_EOL;
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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

    private function allowedSiteTypes(Request $request): array
    {
        $user = $request->user();
        $map = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
        ];

        $allowed = collect($map)
            ->filter(fn (string $permission) => $user?->canDo($permission))
            ->keys()
            ->values()
            ->all();

        return $allowed !== [] ? $allowed : array_keys($map);
    }
}
