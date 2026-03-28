<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\SiteHazard;
use App\Models\EmergencyDrill;
use App\Models\WorkplaceInjury;
use App\Models\LoneWorkerAlert;
use App\Models\SafeguardingConcern;
use App\Domain\Governance\Models\NotifiableIncident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class HealthSafetyDashboardController extends Controller
{
    /**
     * H&S Dashboard with KPIs, trends, and recent activity.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $now = Carbon::now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $startOfYear = $now->copy()->startOfYear();
        $sixMonthsAgo = $now->copy()->subMonths(6);

        // ── KPIs ──────────────────────────────────────────────────────────
        $totalIncidents30d = ClientIncident::where('occurred_at', '>=', $thirtyDaysAgo)->count();

        $nearMisses30d = ClientIncident::where('type', 'near_miss')
            ->where('occurred_at', '>=', $thirtyDaysAgo)
            ->count();

        $openHazards = SiteHazard::whereIn('status', ['open', 'in_progress'])
            ->whereNull('deleted_at')
            ->count();

        $overdueActions = SiteHazard::whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now)
            ->whereNull('deleted_at')
            ->count();

        $workplaceInjuriesYtd = WorkplaceInjury::where('injury_date', '>=', $startOfYear)
            ->whereNull('deleted_at')
            ->count();

        $lostTimeDaysYtd = (int) (WorkplaceInjury::where('injury_date', '>=', $startOfYear)
            ->whereNull('deleted_at')
            ->sum('lost_time_days') ?? 0);

        $lastNotifiable = NotifiableIncident::orderByDesc('occurred_at')->value('occurred_at');
        $daysSinceNotifiable = $lastNotifiable
            ? (int) Carbon::parse($lastNotifiable)->diffInDays($now)
            : null;

        // Drill compliance
        $totalSites = DB::table('sites')->whereNull('deleted_at')->count();
        $sitesWithRecentDrill = EmergencyDrill::where('completed_at', '>=', $sixMonthsAgo)
            ->whereNull('deleted_at')
            ->distinct('site_id')
            ->count('site_id');
        $drillCompliancePct = $totalSites > 0
            ? (int) round(($sitesWithRecentDrill / $totalSites) * 100)
            : 0;

        $activeAlerts = LoneWorkerAlert::where('status', 'active')
            ->whereNull('deleted_at')
            ->count();

        $openSafeguarding = SafeguardingConcern::whereIn('status', ['open', 'investigating', 'new'])
            ->whereNull('deleted_at')
            ->count();

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
            'staff_compliance_pct' => 0, // placeholder
        ];

        // ── Incident Trends (12 months) ───────────────────────────────────
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

        // ── Severity Breakdown ────────────────────────────────────────────
        $severityBreakdown = ClientIncident::select('severity', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['submitted', 'reviewed', 'draft'])
            ->groupBy('severity')
            ->pluck('count', 'severity');

        // ── Hazard Summary ────────────────────────────────────────────────
        $hazardSummary = SiteHazard::select('risk_rating', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNull('deleted_at')
            ->groupBy('risk_rating')
            ->pluck('count', 'risk_rating');

        // ── Site Drill Compliance ─────────────────────────────────────────
        $siteDrillCompliance = DB::table('sites')
            ->leftJoin(
                DB::raw('(SELECT site_id, MAX(completed_at) as last_drill_at FROM emergency_drills WHERE deleted_at IS NULL AND completed_at IS NOT NULL GROUP BY site_id) as latest_drills'),
                'sites.id',
                '=',
                'latest_drills.site_id'
            )
            ->whereNull('sites.deleted_at')
            ->select('sites.id', 'sites.name', 'latest_drills.last_drill_at')
            ->orderBy('sites.name')
            ->get()
            ->map(function ($site) use ($sixMonthsAgo, $now) {
                $lastDrill = $site->last_drill_at ? Carbon::parse($site->last_drill_at) : null;
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
                    'last_drill_date' => $site->last_drill_at,
                    'days_since' => $daysSince,
                    'status' => $status,
                ];
            });

        // ── Recent Activity ───────────────────────────────────────────────
        $recentIncidents = ClientIncident::select('id', 'type', 'severity', 'status', 'occurred_at', 'title', 'description')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $recentHazards = SiteHazard::leftJoin('sites', 'site_hazards.site_id', '=', 'sites.id')
            ->select('site_hazards.id', 'site_hazards.hazard_type as type', 'site_hazards.risk_rating', 'site_hazards.status', 'sites.name as site_name')
            ->whereNull('site_hazards.deleted_at')
            ->orderByDesc('site_hazards.created_at')
            ->limit(10)
            ->get();

        return Inertia::render('health-safety/dashboard', [
            'kpis' => $kpis,
            'incident_trends' => $incidentTrends,
            'severity_breakdown' => $severityBreakdown,
            'hazard_summary' => $hazardSummary,
            'site_drill_compliance' => $siteDrillCompliance,
            'recent_incidents' => $recentIncidents,
            'recent_hazards' => $recentHazards,
        ]);
    }

    /**
     * Analytics page with date range filters and deeper breakdowns.
     */
    public function analytics(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subMonths(12)->startOfMonth();
        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        // ── Incident Data by Type ─────────────────────────────────────────
        $incidentData = ClientIncident::select('type', DB::raw('COUNT(*) as count'))
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('type')
            ->orderByDesc('count')
            ->get();

        // ── Hazard Data by Risk Rating ────────────────────────────────────
        $hazardData = SiteHazard::select('risk_rating', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('deleted_at')
            ->groupBy('risk_rating')
            ->get();

        // ── Injury Data ───────────────────────────────────────────────────
        $injuryByType = WorkplaceInjury::select('injury_type as type', DB::raw('COUNT(*) as count'))
            ->whereBetween('injury_date', [$from, $to])
            ->whereNull('deleted_at')
            ->groupBy('injury_type')
            ->orderByDesc('count')
            ->get();

        $injuryByBodyPart = WorkplaceInjury::select('body_part_affected as body_part', DB::raw('COUNT(*) as count'))
            ->whereBetween('injury_date', [$from, $to])
            ->whereNull('deleted_at')
            ->whereNotNull('body_part_affected')
            ->groupBy('body_part_affected')
            ->orderByDesc('count')
            ->get();

        // ── Site Comparison ───────────────────────────────────────────────
        $siteComparison = DB::table('sites')
            ->leftJoin(
                DB::raw("(SELECT cs.site_id, COUNT(DISTINCT ci.id) as total_incidents FROM client_incidents ci JOIN clients c ON ci.client_id = c.id JOIN client_site cs ON c.id = cs.client_id WHERE ci.occurred_at BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}' GROUP BY cs.site_id) as si"),
                'sites.id',
                '=',
                'si.site_id'
            )
            ->leftJoin(
                DB::raw("(SELECT site_id, COUNT(*) as open_hazards FROM site_hazards WHERE status IN ('open','in_progress') AND deleted_at IS NULL GROUP BY site_id) as sh"),
                'sites.id',
                '=',
                'sh.site_id'
            )
            ->leftJoin(
                DB::raw("(SELECT wi.site_id, SUM(wi.lost_time_days) as lost_time_days FROM workplace_injuries wi WHERE wi.injury_date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}' AND wi.deleted_at IS NULL GROUP BY wi.site_id) as wl"),
                'sites.id',
                '=',
                'wl.site_id'
            )
            ->leftJoin(
                DB::raw('(SELECT site_id, MAX(completed_at) as last_drill FROM emergency_drills WHERE deleted_at IS NULL AND completed_at IS NOT NULL GROUP BY site_id) as ed'),
                'sites.id',
                '=',
                'ed.site_id'
            )
            ->whereNull('sites.deleted_at')
            ->select(
                'sites.id',
                'sites.name',
                DB::raw('COALESCE(si.total_incidents, 0) as total_incidents'),
                DB::raw('COALESCE(sh.open_hazards, 0) as open_hazards'),
                DB::raw('COALESCE(wl.lost_time_days, 0) as lost_time_days'),
                'ed.last_drill'
            )
            ->orderBy('sites.name')
            ->get()
            ->map(function ($site) {
                $sixMonthsAgo = Carbon::now()->subMonths(6);
                $lastDrill = $site->last_drill ? Carbon::parse($site->last_drill) : null;
                $drillStatus = 'overdue';
                if ($lastDrill && $lastDrill->gte($sixMonthsAgo)) {
                    $drillStatus = 'compliant';
                } elseif ($lastDrill && $lastDrill->gte($sixMonthsAgo->copy()->subMonth())) {
                    $drillStatus = 'due_soon';
                }

                // Simple compliance score: weighted from incidents, hazards, drill
                $score = 100;
                $score -= min($site->total_incidents * 5, 30);
                $score -= min($site->open_hazards * 10, 30);
                if ($drillStatus === 'overdue') $score -= 20;
                elseif ($drillStatus === 'due_soon') $score -= 10;

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'total_incidents' => (int) $site->total_incidents,
                    'open_hazards' => (int) $site->open_hazards,
                    'lost_time_days' => (int) $site->lost_time_days,
                    'drill_status' => $drillStatus,
                    'compliance_score' => max(0, $score),
                ];
            });

        // ── Root Cause Analysis ───────────────────────────────────────────
        $rootCauseRaw = ClientIncident::select('root_cause_category', DB::raw('COUNT(*) as count'))
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('root_cause_category')
            ->groupBy('root_cause_category')
            ->orderByDesc('count')
            ->get();

        $rootCauseTotal = $rootCauseRaw->sum('count') ?: 1;
        $rootCauseData = $rootCauseRaw->map(function ($item) use ($rootCauseTotal) {
            return [
                'category' => $item->root_cause_category,
                'count' => $item->count,
                'percentage' => (int) round(($item->count / $rootCauseTotal) * 100),
            ];
        });

        return Inertia::render('health-safety/analytics', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'incident_data' => $incidentData,
            'hazard_data' => $hazardData,
            'injury_data' => [
                'by_type' => $injuryByType,
                'by_body_part' => $injuryByBodyPart,
            ],
            'site_comparison' => $siteComparison,
            'root_cause_data' => $rootCauseData,
        ]);
    }
}
