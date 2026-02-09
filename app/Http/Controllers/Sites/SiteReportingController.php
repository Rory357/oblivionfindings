<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteChecklistRun;
use App\Models\SiteInspectionRecord;
use App\Services\Sites\SiteHazardRiskCalculator;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SiteReportingController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('reports.sites.view');

        $sites = Site::active()
            ->select(['id', 'name', 'type', 'region'])
            ->orderBy('name')
            ->get();

        return inertia('sites/reports/index', [
            'sites' => $sites,
        ]);
    }

    public function houses(Request $request)
    {
        $this->authorize('reports.sites.view');

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
        $this->authorize('reports.sites.view');

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
        $this->authorize('reports.sites.view');

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
        $this->authorize('reports.sites.export');

        $type = $request->input('type', 'houses');
        $format = $request->input('format', 'csv');

        // Generate export based on type and format
        // This would typically use Laravel Excel or similar

        return response()->json(['message' => 'Export generated']);
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
}
