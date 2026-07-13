<?php

namespace App\Http\Controllers\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetIncident;
use App\Models\HsCommittee;
use App\Models\HsRepresentative;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\SafeguardingConcern;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\DrillComplianceService;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsKpiService;
use App\Services\HealthSafety\RestraintKpiService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HealthSafetyDashboardController extends Controller
{
    public function __construct(
        private readonly HsDashboardService $dashboardService,
        private readonly HsAnalyticsService $analyticsService,
        private readonly HsKpiService $kpiService,
        private readonly RestraintKpiService $restraintKpiService,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * H&S Dashboard with KPIs, trends, and recent activity.
     */
    public function index(Request $request): Response
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

        // Drill compliance — single source of truth (reconciles with the drills
        // register hero, analytics site league + site-profile Drills badge).
        $drillCompliancePct = app(DrillComplianceService::class)->compliancePct();

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
            // PPE compliance (cross-module B2) — site-scoped when a site filter is active.
            'ppe_inspections_overdue' => PpeInventory::query()
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->whereNotIn('status', ['condemned', 'disposed'])
                ->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<', $now->toDateString())->count(),
            'ppe_expiring' => PpeInventory::query()
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->whereNotIn('status', ['condemned', 'disposed'])
                ->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $now->copy()->addDays(60)->toDateString())->count(),
            'ppe_unacknowledged' => PpeAllocation::query()
                ->whereNull('returned_at')->where('acknowledged', false)
                ->when($siteId, fn ($q) => $q->whereHas('ppeInventory', fn ($iq) => $iq->where('site_id', $siteId)))
                ->count(),
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
        $user = $request->user();
        $hsSiteBypass = ['healthSafety.viewAllSites'];
        $siteQuery = Site::query()->orderBy('name');
        $this->siteAccess->applySiteScope($siteQuery, $user, $hsSiteBypass);
        $clientQuery = Client::query()->orderBy('first_name');
        $this->siteAccess->applyClientScope($clientQuery, $user, $hsSiteBypass);
        if ($user?->organization_id !== null) {
            $clientQuery->where(fn ($organizationQuery) => $organizationQuery
                ->whereNull('organization_id')
                ->orWhere('organization_id', $user->organization_id));
        }

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
            'sites' => $siteQuery->get(['id', 'name']),
            // Org/brand name comes from Settings → Branding (AppSetting `branding.name`), the
            // same source the rest of the app reads — set it there, not on the organizations row.
            'org_name' => rescue(fn () => AppSetting::query()->where('key', 'branding.name')->value('value') ?? config('app.name'), null, false),
            'clients' => $clientQuery->get(['id', 'first_name', 'last_name', 'site_id']),
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

            // First-aid activity (leading care-activity signal, trailing 30 days) — surfaced
            // on the Leading tab. First-aid-only treatment is NOT recordable / excluded from TRIFR.
            'first_aid' => rescue(fn () => $this->kpiService->firstAidActivity(null, null, $siteId), ['treatments' => 0, 'ambulance' => 0, 'hospital' => 0], false),

            // Safe Work Procedures hub card — approved count + review-due + high-risk coverage gaps.
            'procedures' => rescue(function () {
                $highRisk = ['manual_handling', 'challenging_behaviour', 'lone_working', 'medication'];
                $covered = SafeWorkProcedure::query()->where('status', 'approved')
                    ->whereIn('category', $highRisk)->distinct()->pluck('category')->all();

                return [
                    'approved' => SafeWorkProcedure::query()->where('status', 'approved')->count(),
                    'review_due' => SafeWorkProcedure::query()->where('status', 'approved')
                        ->whereNotNull('review_date')->where('review_date', '<=', now()->addDays(30))->count(),
                    'coverage_gap_categories' => count(array_diff($highRisk, $covered)),
                ];
            }, ['approved' => 0, 'review_due' => 0, 'coverage_gap_categories' => 0], false),

            // Restraint & behaviour-support governance (Ngā Paerewa least-restrictive practice) —
            // lagging signals + unreviewed queue. Surfaced on the Lagging tab. See RestraintKpiService.
            'restraints' => rescue(fn () => [
                'summary' => $this->restraintKpiService->summary($siteId, $from, $to),
                'unreviewed' => $this->restraintKpiService->unreviewedWorklist($siteId),
            ], [
                'summary' => [
                    'events_in_period' => 0, 'out_of_plan' => 0, 'with_injury' => 0, 'critical' => 0,
                    'unreviewed' => 0, 'active_plans' => 0, 'plans_review_due' => 0, 'clients_no_active_bsp' => 0,
                ],
                'unreviewed' => [],
            ], false),
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
    public function analytics(Request $request): Response
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

            // Restraint & behaviour-support breakdowns (Ngā Paerewa) — additive analytics
            // section, site/period scoped. See RestraintKpiService.
            'restraint_analytics' => rescue(fn () => [
                'summary' => $this->restraintKpiService->summary($siteId, $from, $to),
                'breakdowns' => $this->restraintKpiService->breakdowns($siteId, $from, $to),
            ], [
                'summary' => [
                    'events_in_period' => 0, 'out_of_plan' => 0, 'with_injury' => 0, 'critical' => 0,
                    'unreviewed' => 0, 'active_plans' => 0, 'plans_review_due' => 0, 'clients_no_active_bsp' => 0,
                ],
                'breakdowns' => ['by_type' => [], 'by_severity' => [], 'by_plan_status' => []],
            ], false),
        ]));
    }

    /**
     * CSV export of the active analytics view (read-only register records).
     * Honours the same period / site_id / drill filters as the page.
     */
    public function analyticsExport(Request $request): StreamedResponse
    {
        $period = (string) $request->input('period', 'ytd');
        [$from, $to] = $this->resolveRange($period, $request->input('from'), $request->input('to'));
        $siteId = $request->input('site_id') ? (int) $request->input('site_id') : null;
        $view = (string) $request->input('view', 'incidents');

        $data = $this->analyticsService->exportRows($view, $siteId, $from, $to, $this->drillFilters($request));
        $filename = "hs_analytics_{$data['name']}_".now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                $this->putCsv($out, $row);
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
    public function analyticsRecords(Request $request): JsonResponse
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
