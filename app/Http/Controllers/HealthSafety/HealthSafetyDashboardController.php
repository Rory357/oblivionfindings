<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\FleetIncident;
use App\Models\HsCommittee;
use App\Models\HsRepresentative;
use App\Models\LoneWorkerAlert;
use App\Models\AppSetting;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Domain\Governance\Models\NotifiableIncident;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsKpiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HealthSafetyDashboardController extends Controller
{
    public function __construct(
        private readonly HsDashboardService $dashboardService,
        private readonly HsKpiService $kpiService,
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

        // Period range (G4), site filter (G4) and role lens (G3) — replace the fixed snapshot.
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $thirtyDaysAgo;
        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : $now;
        $siteId = $request->integer('site') ?: null;
        $lens = in_array($request->input('lens'), ['governance', 'manager', 'frontline'], true)
            ? $request->input('lens')
            : 'manager';

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
            'staff_compliance_pct' => (int) round($this->kpiService->trainingAuditCompliancePct() ?? 0),
            'days_since_lti' => $this->kpiService->daysSinceLostTimeInjury($siteId),
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

        // ── H&S Backbone summary (PR5 addition — additive) ──
        $backboneSummary = $this->dashboardService->getDashboardSummary($thirtyDaysAgo);

        return Inertia::render('health-safety/dashboard', [
            'kpis' => $kpis,
            'incident_trends' => $incidentTrends,
            'backbone' => $backboneSummary,

            // ── Command-centre additions — period/site/lens-aware (G3/G4/G5/G6) ──
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'site' => $siteId,
                'lens' => $lens,
            ],
            'lens' => $lens,
            'sites' => Site::orderBy('name')->get(['id', 'name']),
            // Org/brand name comes from Settings → Branding (AppSetting `branding.name`), the
            // same source the rest of the app reads — set it there, not on the organizations row.
            'org_name' => rescue(fn () => AppSetting::query()->where('key', 'branding.name')->value('value') ?? config('app.name'), null, false),
            'clients' => Client::orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]),
            'staff' => User::query()
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['client', 'next_of_kin']))
                ->orderBy('name')
                ->get(['id', 'name']),
            'leading_lagging' => $this->kpiService->leadingLagging($from, $to, $siteId),
            'frequency_trends' => $this->kpiService->monthlyFrequencyRates(12, $siteId),
            'worklists' => [
                'overdue_corrective_actions' => $this->dashboardService->overdueCorrectiveActions($siteId),
                'open_investigations' => $this->dashboardService->openInvestigations($siteId),
                'notifiable_events' => $this->dashboardService->notifiableEvents(),
                'expiring' => $this->dashboardService->expiringFeed($siteId),
            ],
            'frequency_operands' => $this->kpiService->nearMissOperands($from, $to, $siteId),
            'hazard_burndown' => $this->kpiService->hazardBurndown(6, $siteId),
            'incidents_by_category' => ClientIncident::select('type', DB::raw('COUNT(*) as count'))
                ->whereBetween('occurred_at', [$from, $to])
                ->where('type', '!=', 'near_miss')
                ->when($siteId, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('site_id', $siteId)))
                ->groupBy('type')
                ->orderByDesc('count')
                ->limit(6)
                ->get()
                ->map(fn ($r) => ['label' => $r->type, 'count' => (int) $r->count]),
            'site_league' => $this->dashboardService->siteLeague($from, $to),

            // Leading tab — open-hazards list (row 3) + worker-participation KPI (row 1).
            // Both rescue-guarded so a query issue degrades gracefully rather than 500-ing the page.
            'open_hazards_list' => rescue(fn () => SiteHazard::query()
                ->with('site:id,name')
                ->whereIn('status', ['open', 'in_progress'])
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->orderByRaw("CASE risk_rating WHEN 'extreme' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END DESC")
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'site_id' => $h->site_id,
                    'title' => $h->description
                        ?: ($h->custom_hazard_type ?: ucwords(str_replace('_', ' ', (string) ($h->hazard_type ?? 'Hazard')))),
                    'risk_rating' => $h->risk_rating,
                    'site' => $h->site?->name,
                ])
                ->all(), [], false),
            'worker_participation' => rescue(function () {
                $totalSites = Site::count();
                $sitesWithRep = HsRepresentative::whereNotNull('site_id')->distinct('site_id')->count('site_id');

                return [
                    'pct' => $totalSites > 0 ? (int) round($sitesWithRep / $totalSites * 100) : null,
                    'committees' => HsCommittee::count(),
                ];
            }, ['pct' => null, 'committees' => 0], false),
        ]);
    }

    /**
     * Analytics page with date range filters and deeper breakdowns.
     */
    public function analytics(Request $request): \Inertia\Response
    {
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subMonths(12)->startOfMonth();
        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        // -- Incident Data by Type --
        $incidentData = ClientIncident::select('type', DB::raw('COUNT(*) as count'))
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('type')
            ->orderByDesc('count')
            ->get();

        // -- Hazard Data by Risk Rating --
        $hazardData = SiteHazard::select('risk_rating', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('risk_rating')
            ->get();

        // -- Injury Data --
        $injuryByType = WorkplaceInjury::select('injury_type as type', DB::raw('COUNT(*) as count'))
            ->whereBetween('injury_date', [$from, $to])
            ->groupBy('injury_type')
            ->orderByDesc('count')
            ->get();

        $injuryByBodyPart = WorkplaceInjury::select('body_part_affected as body_part', DB::raw('COUNT(*) as count'))
            ->whereBetween('injury_date', [$from, $to])
            ->whereNotNull('body_part_affected')
            ->groupBy('body_part_affected')
            ->orderByDesc('count')
            ->get();

        // -- Site Comparison --
        $sixMonthsAgo = Carbon::now()->subMonths(6);
        $siteComparison = Site::orderBy('name')->get(['id', 'name'])->map(function ($site) use ($from, $to, $sixMonthsAgo) {
            // NOTE: site_comparison.total_incidents is unscoped (a known bug) — left as-is here
            // because the concurrent /health-safety/analytics rebuild (branch claude/sharp-hypatia-*)
            // owns analytics() and fixes it via client.site_id. Avoid a cross-branch conflict.
            $totalIncidents = ClientIncident::whereBetween('occurred_at', [$from, $to])->count();

            $openHazards = SiteHazard::where('site_id', $site->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->count();

            $lostTimeDays = (int) WorkplaceInjury::where('site_id', $site->id)
                ->whereBetween('injury_date', [$from, $to])
                ->sum('lost_time_days');

            $lastDrill = EmergencyDrill::where('site_id', $site->id)
                ->whereNotNull('completed_at')
                ->max('completed_at');

            $lastDrillDate = $lastDrill ? Carbon::parse($lastDrill) : null;
            $drillStatus = 'overdue';
            if ($lastDrillDate && $lastDrillDate->gte($sixMonthsAgo)) {
                $drillStatus = 'compliant';
            } elseif ($lastDrillDate && $lastDrillDate->gte($sixMonthsAgo->copy()->subMonth())) {
                $drillStatus = 'due_soon';
            }

            $score = 100;
            $score -= min($totalIncidents * 5, 30);
            $score -= min($openHazards * 10, 30);
            if ($drillStatus === 'overdue') $score -= 20;
            elseif ($drillStatus === 'due_soon') $score -= 10;

            return [
                'id' => $site->id,
                'name' => $site->name,
                'total_incidents' => $totalIncidents,
                'open_hazards' => $openHazards,
                'lost_time_days' => $lostTimeDays,
                'drill_status' => $drillStatus,
                'compliance_score' => max(0, $score),
            ];
        });

        // -- Root Cause Analysis --
        $rootCauseRaw = ClientIncident::select('root_cause_category', DB::raw('COUNT(*) as count'))
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('root_cause_category')
            ->groupBy('root_cause_category')
            ->orderByDesc('count')
            ->get();

        $rootCauseTotal = $rootCauseRaw->sum('count') ?: 1;
        $rootCauseData = $rootCauseRaw->map(fn ($item) => [
            'category' => $item->root_cause_category,
            'count' => $item->count,
            'percentage' => (int) round(($item->count / $rootCauseTotal) * 100),
        ]);

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
