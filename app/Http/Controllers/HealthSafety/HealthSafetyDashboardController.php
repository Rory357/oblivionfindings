<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\FleetIncident;
use App\Models\LoneWorkerAlert;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\WorkplaceInjury;
use App\Domain\Governance\Models\NotifiableIncident;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HealthSafetyDashboardController extends Controller
{
    public function __construct(
        private readonly HsDashboardService $dashboardService,
        private readonly HsAnalyticsService $analyticsService,
    ) {}
    /**
     * H&S Dashboard with KPIs, trends, and recent activity.
     */
    public function index(Request $request): \Inertia\Response
    {
        $now = Carbon::now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $startOfYear = $now->copy()->startOfYear();
        $sixMonthsAgo = $now->copy()->subMonths(6);

        // -- KPIs --
        $totalIncidents30d = ClientIncident::where('occurred_at', '>=', $thirtyDaysAgo)->count();

        $nearMisses30d = ClientIncident::where('type', 'near_miss')
            ->where('occurred_at', '>=', $thirtyDaysAgo)
            ->count();

        $openHazards = SiteHazard::whereIn('status', ['open', 'in_progress'])->count();

        $overdueActions = SiteHazard::whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now)
            ->count();

        $workplaceInjuriesYtd = WorkplaceInjury::where('injury_date', '>=', $startOfYear)->count();

        $lostTimeDaysYtd = (int) (WorkplaceInjury::where('injury_date', '>=', $startOfYear)
            ->sum('lost_time_days') ?? 0);

        $lastNotifiable = NotifiableIncident::orderByDesc('occurred_at')->value('occurred_at');
        $daysSinceNotifiable = $lastNotifiable
            ? (int) Carbon::parse($lastNotifiable)->diffInDays($now)
            : null;

        // Drill compliance
        $totalSites = Site::count();
        $sitesWithRecentDrill = EmergencyDrill::where('completed_at', '>=', $sixMonthsAgo)
            ->distinct('site_id')
            ->count('site_id');
        $drillCompliancePct = $totalSites > 0
            ? (int) round(($sitesWithRecentDrill / $totalSites) * 100)
            : 0;

        // Lone worker active alerts — canonical ControlRoomAlert is the operational source of truth
        $activeAlerts = ControlRoomAlert::where('source', 'lone_worker')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $openSafeguarding = SafeguardingConcern::whereIn('status', ['open', 'investigating', 'new'])->count();

        // Fleet incidents
        $fleetIncidents30d = FleetIncident::where('occurred_at', '>=', $thirtyDaysAgo)->count();
        $fleetUnresolved = FleetIncident::whereIn('status', ['reported', 'investigating'])->count();

        $kpis = [
            'incidents_30d' => $totalIncidents30d,
            'near_misses_30d' => $nearMisses30d,
            'open_hazards' => $openHazards,
            'overdue_actions' => $overdueActions,
            'workplace_injuries_ytd' => $workplaceInjuriesYtd,
            'lost_time_days_ytd' => $lostTimeDaysYtd,
            'days_since_notifiable' => $daysSinceNotifiable ?? 0,
            'drill_compliance_pct' => $drillCompliancePct,
            'active_alerts' => $activeAlerts,
            'open_safeguarding' => $openSafeguarding,
            'fleet_incidents_30d' => $fleetIncidents30d,
            'fleet_unresolved' => $fleetUnresolved,
            'staff_compliance_pct' => 0,
        ];

        // -- Incident Trends (12 months) --
        $twelveMonthsAgo = $now->copy()->subMonths(12)->startOfMonth();
        $incidentTrends = ClientIncident::select(
                DB::raw("DATE_FORMAT(occurred_at, '%Y-%m') as month"),
                'type',
                DB::raw('COUNT(*) as count')
            )
            ->where('occurred_at', '>=', $twelveMonthsAgo)
            ->groupBy('month', 'type')
            ->orderBy('month')
            ->get()
            ->groupBy('month')
            ->map(function ($items, $month) {
                $types = [];
                $total = 0;
                foreach ($items as $item) {
                    $types[$item->type] = $item->count;
                    $total += $item->count;
                }
                return [
                    'month' => $month,
                    'count' => $total,
                    'types' => $types,
                ];
            })
            ->values();

        // -- Severity Breakdown --
        $severityBreakdown = ClientIncident::select('severity', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['submitted', 'reviewed', 'draft'])
            ->groupBy('severity')
            ->pluck('count', 'severity');

        // -- Hazard Summary --
        $hazardSummary = SiteHazard::select('risk_rating', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['open', 'in_progress'])
            ->groupBy('risk_rating')
            ->pluck('count', 'risk_rating');

        // -- Site Drill Compliance --
        $siteDrillCompliance = Site::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($site) use ($sixMonthsAgo, $now) {
                $lastDrillAt = EmergencyDrill::where('site_id', $site->id)
                    ->whereNotNull('completed_at')
                    ->max('completed_at');

                $lastDrill = $lastDrillAt ? Carbon::parse($lastDrillAt) : null;
                $daysSince = $lastDrill ? (int) $lastDrill->diffInDays($now) : null;

                if ($lastDrill && $lastDrill->gte($sixMonthsAgo)) {
                    $status = 'compliant';
                } elseif ($lastDrill && $lastDrill->gte($sixMonthsAgo->copy()->subMonth())) {
                    $status = 'due_soon';
                } else {
                    $status = 'overdue';
                }

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'last_drill_date' => $lastDrillAt,
                    'days_since' => $daysSince,
                    'status' => $status,
                ];
            });

        // -- Recent Activity --
        $recentIncidents = ClientIncident::select('id', 'type', 'severity', 'status', 'occurred_at', 'title', 'description')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $recentHazards = SiteHazard::with('site:id,name')
            ->select('id', 'hazard_type', 'risk_rating', 'status', 'site_id', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'type' => $h->hazard_type,
                'risk_rating' => $h->risk_rating,
                'status' => $h->status,
                'site_name' => $h->site?->name,
            ]);

        // ── H&S Backbone summary (PR5 addition — additive) ──
        $backboneSummary = $this->dashboardService->getDashboardSummary($thirtyDaysAgo);

        return Inertia::render('health-safety/dashboard', [
            'kpis' => $kpis,
            'incident_trends' => $incidentTrends,
            'severity_breakdown' => $severityBreakdown,
            'hazard_summary' => $hazardSummary,
            'site_drill_compliance' => $siteDrillCompliance,
            'recent_incidents' => $recentIncidents,
            'recent_hazards' => $recentHazards,
            'recent_fleet_incidents' => FleetIncident::with('asset:id,name')
                ->select('id', 'incident_type', 'severity', 'status', 'occurred_at', 'location')
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get(),
            'backbone' => $backboneSummary,
        ]);
    }

    /**
     * Health & Safety Analytics — trend / root-cause / governance explorer.
     *
     * Accepts a range preset (period) or a custom from/to window, a site_id
     * filter and a role lens; returns site- & role-scoped payloads built by
     * HsAnalyticsService. NZ-only metrics (LTIFR/TRIFR, WorkSafe notifiable,
     * Nga Paerewa). See docs/HEALTH_SAFETY_ANALYTICS_BACKEND_AUDIT.md.
     */
    public function analytics(Request $request): \Inertia\Response
    {
        $period = (string) $request->input('period', 'ytd'); // 30d|q|6m|ytd|custom
        [$from, $to] = $this->resolveRange($period, $request->input('from'), $request->input('to'));

        $siteId = $request->input('site_id') ? (int) $request->input('site_id') : null;
        $lens = in_array($request->input('lens'), ['governance', 'manager', 'frontline'], true)
            ? (string) $request->input('lens')
            : 'manager';

        $payload = $this->analyticsService->build($siteId, $from, $to, $lens);
        $activeSite = $siteId ? Site::find($siteId) : null;

        return Inertia::render('health-safety/analytics', array_merge($payload, [
            'filters' => [
                'period' => $period,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'site_id' => $siteId,
                'lens' => $lens,
            ],
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]));
    }

    /**
     * CSV export of the active analytics view (read-only register records).
     * Honours the same period / site_id / drill filters as the page.
     */
    public function analyticsExport(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $period = (string) $request->input('period', 'ytd');
        [$from, $to] = $this->resolveRange($period, $request->input('from'), $request->input('to'));
        $siteId = $request->input('site_id') ? (int) $request->input('site_id') : null;
        $view = (string) $request->input('view', 'incidents');

        $data = $this->analyticsService->exportRows($view, $siteId, $from, $to, $this->drillFilters($request));
        $filename = "hs_analytics_{$data['name']}_".now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * JSON records for the read-only drill-in detail modal. Same scoping as
     * the page + the clicked breakdown's drill filter. Capped for display.
     */
    public function analyticsRecords(Request $request): \Illuminate\Http\JsonResponse
    {
        $period = (string) $request->input('period', 'ytd');
        [$from, $to] = $this->resolveRange($period, $request->input('from'), $request->input('to'));
        $siteId = $request->input('site_id') ? (int) $request->input('site_id') : null;
        $view = (string) $request->input('view', 'incidents');

        $data = $this->analyticsService->exportRows($view, $siteId, $from, $to, $this->drillFilters($request));

        return response()->json([
            'name' => $data['name'],
            'headers' => $data['headers'],
            'rows' => array_slice($data['rows'], 0, 200),
            'total' => count($data['rows']),
        ]);
    }

    /** @return array<string,string> the drill sub-filters present on the request */
    private function drillFilters(Request $request): array
    {
        return array_filter([
            'type' => $request->input('type'),
            'severity' => $request->input('severity'),
            'cause' => $request->input('cause'),
            'risk' => $request->input('risk'),
            'status' => $request->input('drill_status'),
            'body_part' => $request->input('body_part'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Map a range preset (or custom from/to) to a [from, to] window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(string $period, ?string $from, ?string $to): array
    {
        $now = Carbon::now();

        return match ($period) {
            '30d' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'q' => [$now->copy()->subMonths(3)->startOfDay(), $now->copy()->endOfDay()],
            '6m' => [$now->copy()->subMonths(6)->startOfDay(), $now->copy()->endOfDay()],
            'custom' => [
                $from ? Carbon::parse($from)->startOfDay() : $now->copy()->subMonths(12)->startOfDay(),
                $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay(),
            ],
            default => [$now->copy()->startOfYear(), $now->copy()->endOfDay()], // ytd
        };
    }
}
