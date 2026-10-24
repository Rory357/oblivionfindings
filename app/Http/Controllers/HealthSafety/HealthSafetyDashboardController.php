<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsCommittee;
use App\Models\HsEvent;
use App\Models\HsRepresentative;
use App\Models\SafeguardingConcern;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\Fleet\FleetIncidentReadScope;
use App\Services\HealthSafety\DrillComplianceService;
use App\Services\HealthSafety\DashboardClientOptionsReadService;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsKpiService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\PpeSiteReadService;
use App\Services\HealthSafety\RestraintKpiService;
use App\Services\Incidents\IncidentAccessService;
use App\Services\UserSiteAccessService;
use App\Support\Incidents\IncidentOccurrencePeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class HealthSafetyDashboardController extends Controller
{
    private const HS_SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

    public function __construct(
        private readonly HsDashboardService $dashboardService,
        private readonly HsAnalyticsService $analyticsService,
        private readonly HsKpiService $kpiService,
        private readonly RestraintKpiService $restraintKpiService,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** Identifiable options require both the H&S Site audience and actual Client read authority. */
    private function readableClientOptions(DashboardClientOptionsReadService $options, HealthSafetyReadContext $context, ?int $siteId): array
    {
        return $options->options($context, $siteId);
    }

    /**
     * H&S Dashboard with KPIs, trends, and recent activity.
     */
    public function index(Request $request): Response
    {
        $props = HealthSafetyReadContext::within($request->user(), $this->sourceReadPermissions(),
            fn (HealthSafetyReadContext $context): array => HealthSafetyReadMaterialisationProof::within($context,
                fn (HealthSafetyReadMaterialisationProof $proof): array => $this->overviewProps($request, $context, $proof)));

        // These already materialised operands must refresh together even on an
        // auth-only partial response; identity alone cannot prove Site access.
        foreach (['worker_participation', 'filters', 'sites'] as $key) {
            $props[$key] = Inertia::always($props[$key]);
        }

        return Inertia::render('health-safety/dashboard', $props);
    }

    /** The Site comparison needs current staff with approved Sites. Without them the comparison is withheld (empty),
     * like the PPE numbers above, instead of denying the whole overview. A refused Site choice (404) still refuses. */
    private function siteLeagueOrEmpty(?Carbon $from, ?Carbon $to, int|array|null $siteScope, ?User $user, HealthSafetyReadContext $context): array
    {
        try {
            return $this->dashboardService->siteLeague($from, $to, $siteScope, $user, $context);
        } catch (HttpExceptionInterface $denial) {
            if ($denial->getStatusCode() !== 403) {
                throw $denial;
            }

            return [];
        }
    }

    private function overviewProps(Request $request, HealthSafetyReadContext $context, HealthSafetyReadMaterialisationProof $proof): array
    {
        $user = $context->consumeSourceViewer([], fn (User $current): User => $current);
        [$siteId, $siteIds] = $this->resolveSiteContext($request, 'site', $user);
        $clientOptionsRead = app(DashboardClientOptionsReadService::class);
        $clientOptionsRead->prepare($context, $siteId, $siteIds);
        $now = Carbon::now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $startOfYear = $now->copy()->startOfYear();
        $sixMonthsAgo = $now->copy()->subMonths(6);

        $selectedIncidentDates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        // Period range (G4), site filter (G4) and role lens (G3) — replace the fixed snapshot.
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $thirtyDaysAgo;
        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : $now;
        $workerToday = $now->copy()->setTimezone(IncidentOccurrencePeriod::timezone());
        // Incident navigation carries civil dates; independent rolling snapshots keep their instants.
        $incidentTo = $selectedIncidentDates['to'] ?? $workerToday->toDateString();
        $incidentPeriod = [
            'from' => $selectedIncidentDates['from'] ?? Carbon::parse($incidentTo)->subDays(30)->toDateString(),
            'to' => $incidentTo,
        ];
        validator($incidentPeriod, ['to' => ['after_or_equal:from']])->validate();
        $siteScope = $siteId ?? $siteIds;
        $incidentFilters = [...$incidentPeriod, 'site_id' => $siteId];
        $incidentCategories = IncidentOccurrencePeriod::apply(ClientIncident::query(), $incidentPeriod['from'], $incidentPeriod['to'])
            ->where('type', '!=', 'near_miss');
        if ($siteId !== null) {
            app(IncidentAccessService::class)->forSiteIds($incidentCategories, [$siteId]);
        }
        $incidentCategories = $proof->protect($context->applyIncidentSourceScope($incidentCategories));
        $hsSiteBypass = self::HS_SITE_BYPASS_PERMISSIONS;
        $lens = in_array($request->input('lens'), ['governance', 'manager', 'frontline'], true)
            ? $request->input('lens')
            : 'manager';

        // -- KPIs --
        $incidentSnapshot = $this->incidentOverviewSnapshot($user, $siteIds, $now, $context);
        $totalIncidents30d = $incidentSnapshot['incidents_30d'];
        $nearMisses30d = $incidentSnapshot['near_misses_30d'];

        $openHazardQuery = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress']);
        $this->applySiteIds($openHazardQuery, $siteIds);
        $openHazards = (clone $openHazardQuery)->count();

        $overdueActions = (clone $openHazardQuery)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now)
            ->count();

        $injuryOperands = $this->kpiService->workplaceInjuryOperands($startOfYear, $now, $siteScope, $user, $context);
        $workplaceInjuriesYtd = $injuryOperands['injuries'];
        $lostTimeDaysYtd = $injuryOperands['lost_time_days'];

        $lastNotifiable = $incidentSnapshot['last_notifiable'];
        $daysSinceNotifiable = $lastNotifiable
            ? (int) Carbon::parse($lastNotifiable)->diffInDays($now)
            : null;

        // Drill compliance — single source of truth (reconciles with the drills
        // register hero, analytics site league + site-profile Drills badge).
        $drillCompliancePct = app(DrillComplianceService::class)->compliancePct($siteScope);

        // Lone worker active alerts — canonical ControlRoomAlert is the operational source of truth
        $activeAlertsQuery = ControlRoomAlert::query()
            ->where('source', 'lone_worker')
            ->actionable();
        $this->applySiteIds($activeAlertsQuery, $siteIds);
        $this->siteAccess->applyAlertScope($activeAlertsQuery, $user, $hsSiteBypass);
        $activeAlerts = $activeAlertsQuery->count();

        $openSafeguarding = SafeguardingConcern::query()->open();
        $this->applySiteIds($openSafeguarding, $siteIds);
        $openSafeguarding = $openSafeguarding->count();

        // Preparation shares fresh evidence; Fleet retains its own read and asset Site authority.
        $fleet = ['access' => 'restricted', 'rolling_count' => null, 'open_count' => null];
        if ($user !== null) {
            $fleet = app(FleetIncidentReadScope::class)->dashboardCountsWithOriginalContext($user, $context, $siteIds,
                $thirtyDaysAgo->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'), $now->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'));
        }
        $fleetIncidents30d = $fleet['rolling_count'];
        $fleetUnresolved = $fleet['open_count'];
        $staffCompliancePct = $this->kpiService->trainingAuditCompliancePct($siteScope);

        $ppeKeys = ['ppe_inspections_overdue', 'ppe_expiring', 'ppe_unacknowledged'];
        $ppeKpis = ['counts' => array_fill_keys($ppeKeys, null),
            'integrity' => array_fill_keys($ppeKeys, 'restricted'), 'repair_counts' => array_fill_keys($ppeKeys, null),
            'physical_inventory_count' => null, 'physical_allocation_count' => null];
        if ($user !== null) {
            try {
                $ppeKpis = app(PpeSiteReadService::class)->kpisForViewer($user, $siteIds);
            } catch (HttpExceptionInterface $denial) {
                if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                    throw $denial;
                }
            }
        }

        $kpis = [
            'incidents_30d' => $totalIncidents30d,
            'near_misses_30d' => $nearMisses30d,
            'open_hazards' => $openHazards,
            'overdue_actions' => $overdueActions,
            'workplace_injuries_ytd' => $workplaceInjuriesYtd,
            'lost_time_days_ytd' => $lostTimeDaysYtd,
            'days_since_notifiable' => $daysSinceNotifiable,
            'drill_compliance_pct' => $drillCompliancePct,
            'active_alerts' => $activeAlerts,
            'open_safeguarding' => $openSafeguarding,
            'fleet_incidents_30d' => $fleetIncidents30d,
            'fleet_unresolved' => $fleetUnresolved,
            'staff_compliance_pct' => $staffCompliancePct === null ? null : (int) round($staffCompliancePct),
            'days_since_lti' => $this->kpiService->daysSinceLostTimeInjury($siteScope, $user, $context),
            ...$ppeKpis['counts'],
        ];

        // -- Incident Trends (12 months) --
        $incidentTrends = $incidentSnapshot['trends'];

        // ── H&S Backbone summary (PR5 addition — additive) ──
        $backboneSummary = $this->dashboardService->getDashboardSummary(
            $thirtyDaysAgo,
            $siteScope,
            $user,
            $context,
        );
        $siteQuery = Site::query()->orderBy('name');
        $this->siteAccess->applySiteScope($siteQuery, $user, $hsSiteBypass);
        $clientOptions = $this->readableClientOptions($clientOptionsRead, $context, $siteId);

        $staffQuery = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['client', 'next_of_kin']))
            ->orderBy('name');
        $this->siteAccess->applyStaffScope($staffQuery, $user, $hsSiteBypass);
        $this->applyStaffSiteFilter($staffQuery, $siteId);

        $expiringProjection = $this->dashboardService->expiringFeedProjection($siteScope, viewer: $user, context: $context);

        return [
            'kpis' => $kpis,
            'source_scope' => 'readable_subset',
            'source_completeness' => 'unknown',
            'workplace_injury_state' => array_diff_key($injuryOperands, ['injuries' => true, 'lost_time_days' => true]),
            'ppe_kpi_state' => array_diff_key($ppeKpis, ['counts' => true]),
            'incident_trends' => $incidentTrends,
            'backbone' => $backboneSummary,

            // ── Command-centre additions — period/site/lens-aware (G3/G4/G5/G6) ──
            'filters' => [
                'from' => $incidentPeriod['from'],
                'to' => $incidentPeriod['to'],
                'site' => $siteId,
                'lens' => $lens,
            ],
            'lens' => $lens,
            'sites' => $siteQuery->get(['id', 'name'])->toArray(),
            // Org/brand name comes from Settings → Branding (AppSetting `branding.name`), the
            // same source the rest of the app reads — set it there, not on the organizations row.
            'org_name' => rescue(fn () => AppSetting::query()->where('key', 'branding.name')->value('value') ?? config('app.name'), null, false),
            'clients' => $clientOptions,
            'staff' => $staffQuery->get(['id', 'name'])->toArray(),
            'leading_lagging' => $this->kpiService->leadingLagging($from, $to, $siteScope, $user, $context, $incidentFilters),
            'frequency_trends' => $this->kpiService->monthlyFrequencyRates(12, $siteScope, $user, $context),
            'worklists' => [
                'attention' => $this->dashboardService->attentionWorklists($siteScope, $user, context: $context),
                'overdue_corrective_actions' => $this->dashboardService->overdueCorrectiveActions($siteScope, viewer: $user, context: $context),
                'open_investigations' => $this->dashboardService->openInvestigations($siteScope, viewer: $user, context: $context),
                'notifiable_events' => $this->dashboardService->notifiableEvents($siteScope, 10, $user, $context),
                'expiring' => $expiringProjection['items'],
                'ppe_expiring_state' => $expiringProjection['ppe'],
            ],
            'frequency_operands' => $this->kpiService->nearMissOperands(null, null, $siteScope, $user, $context),
            'hazard_burndown' => $this->kpiService->hazardBurndown(6, $siteScope),
            'incidents_by_category' => $incidentCategories->select('type', DB::raw('COUNT(*) as count'))
                ->groupBy('type')
                ->orderByDesc('count')
                ->limit(6)
                ->get()
                ->map(fn ($r) => ['label' => $r->type, 'count' => (int) $r->count])->all(),
            'site_league' => $this->siteLeagueOrEmpty($from, $to, $siteScope, $user, $context),

            // Leading tab — open-hazards list (row 3) + worker-participation KPI (row 1).
            // Participation operands use canonical current source admission and nullable availability.
            'open_hazards_list' => rescue(fn () => SiteHazard::query()
                ->with('site:id,name')
                ->whereIn('status', ['open', 'in_progress'])
                ->when($siteIds !== null, fn ($q) => $this->applySiteIds($q, $siteIds))
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
            'worker_participation' => app(\App\Services\HealthSafety\WorkerParticipationMetricsReadService::class)->overview($user, $siteScope, $context),

            // First-aid activity (leading care-activity signal, trailing 30 days) — surfaced
            // on the Leading tab. First-aid-only treatment is NOT recordable / excluded from TRIFR.
            'first_aid' => $this->kpiService->firstAidActivity(null, null, $siteScope, $user, $context),

            // Safe Work Procedures hub card — approved count + review-due + high-risk coverage gaps.
            'procedures' => rescue(function () use ($siteScope) {
                $highRisk = ['manual_handling', 'challenging_behaviour', 'lone_working', 'medication'];
                $approvedQuery = $this->applicableProcedureQuery($siteScope);
                $covered = (clone $approvedQuery)
                    ->with('publishedVersion')->get()->map(fn ($procedure) => $procedure->publishedValue('category'))->filter(fn ($category) => in_array($category, $highRisk, true))->unique()
                    ->all();

                return [
                    'approved' => (clone $approvedQuery)->count(),
                    'review_due' => (clone $approvedQuery)
                        ->whereNotNull('published_review_date')->where('published_review_date', '<=', now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays(30)->toDateString())->count(),
                    'coverage_gap_categories' => count(array_diff($highRisk, $covered)),
                ];
            }, ['approved' => 0, 'review_due' => 0, 'coverage_gap_categories' => 0], false),

            // Restraint & behaviour-support governance (Ngā Paerewa least-restrictive practice) —
            // lagging signals + unreviewed queue. Surfaced on the Lagging tab. See RestraintKpiService.
            'restraints' => [
                'summary' => $this->restraintKpiService->summary($siteScope, $from, $to, $user, $context),
                'unreviewed' => $this->restraintKpiService->unreviewedWorklist($siteScope, 8, $user, $context),
            ],
        ];
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
        $props = HealthSafetyReadContext::within($request->user(), $this->sourceReadPermissions(),
            fn (HealthSafetyReadContext $context): array => HealthSafetyReadMaterialisationProof::within($context,
                fn (): array => $this->analyticsProps($request, $context)));

        // Retained percentages and deltas must not outlive the freshly read
        // participation audience or become paired with a different filter.
        foreach (['trends', 'scorecard', 'filters', 'sites', 'active_site', 'site_brand_colour'] as $key) {
            $props[$key] = Inertia::always($props[$key]);
        }

        return Inertia::render('health-safety/analytics', $props);
    }

    private function analyticsProps(Request $request, HealthSafetyReadContext $context): array
    {
        $user = $context->consumeSourceViewer([], fn (User $current): User => $current);
        $period = (string) $request->input('period', 'ytd'); // 30d|q|6m|ytd|custom
        [$from, $to] = $this->resolveRange($period, $request->input('from'), $request->input('to'));

        [$siteId, $siteIds] = $this->resolveSiteContext($request, 'site_id', $user);
        $siteScope = $siteId ?? $siteIds;
        $lens = in_array($request->input('lens'), ['governance', 'manager', 'frontline'], true)
            ? (string) $request->input('lens')
            : 'manager';

        $payload = $this->analyticsService->build($siteScope, $from, $to, $lens, $user, $context);
        $activeSite = $siteId ? Site::find($siteId) : null;
        $siteQuery = Site::query()->where('is_active', true)->orderBy('name');
        $this->siteAccess->applySiteScope($siteQuery, $user, self::HS_SITE_BYPASS_PERMISSIONS);

        return array_merge($payload, [
            'filters' => [
                'period' => $period,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'site_id' => $siteId,
                'lens' => $lens,
            ],
            'sites' => $siteQuery->get(['id', 'name'])->toArray(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,

            // Restraint & behaviour-support breakdowns (Ngā Paerewa) — additive analytics
            // section, site/period scoped. See RestraintKpiService.
            'restraint_analytics' => [
                'summary' => $this->restraintKpiService->summary($siteScope, $from, $to, $user, $context),
                'breakdowns' => $this->restraintKpiService->breakdowns($siteScope, $from, $to, $user, $context),
            ],
        ]);
    }

    /**
     * CSV export of the active analytics view (read-only register records).
     * Honours the same period / site_id / drill filters as the page.
     */
    public function analyticsExport(Request $request): StreamedResponse
    {
        $period = (string) $request->input('period', 'ytd');
        [$from, $to] = $this->resolveRange($period, $request->input('from'), $request->input('to'));
        [$siteId, $siteIds] = $this->resolveSiteContext($request, 'site_id');
        $siteScope = $siteId ?? $siteIds;
        $view = (string) $request->input('view', 'incidents');

        $data = $this->analyticsService->exportRows($view, $siteScope, $from, $to, $this->drillFilters($request), $request->user());
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
        [$siteId, $siteIds] = $this->resolveSiteContext($request, 'site_id');
        $siteScope = $siteId ?? $siteIds;
        $view = (string) $request->input('view', 'incidents');

        $data = $this->analyticsService->exportRows($view, $siteScope, $from, $to, $this->drillFilters($request), $request->user());

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

    /** @return array{0: int|null, 1: array<int, int>|null, 2: bool} */
    private function resolveSiteContext(Request $request, string $input, ?User $viewer = null): array
    {
        $user = $viewer ?? $request->user();
        $canViewAllSites = $this->siteAccess->canBypass($user, self::HS_SITE_BYPASS_PERMISSIONS);
        $requestedSiteId = $request->integer($input) ?: null;

        if ($requestedSiteId !== null) {
            $this->siteAccess->assertCanAccessSiteId(
                $user,
                $requestedSiteId,
                self::HS_SITE_BYPASS_PERMISSIONS,
            );

            return [$requestedSiteId, [$requestedSiteId], $canViewAllSites];
        }

        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            self::HS_SITE_BYPASS_PERMISSIONS,
        );
        abort_if($accessibleSiteIds === [], 403, UserSiteAccessService::DEFAULT_MESSAGE);

        if ($canViewAllSites) {
            return [null, $accessibleSiteIds, true];
        }

        $effectiveSiteId = $accessibleSiteIds[0];

        return [$effectiveSiteId, [$effectiveSiteId], false];
    }

    private function applyStaffSiteFilter(Builder $query, ?int $siteId): Builder
    {
        if ($siteId === null) {
            return $query;
        }

        return $query->whereHas('hrEmployeeProfile', fn (Builder $profileQuery) => $profileQuery
            ->where('primary_site_id', $siteId)
            ->orWhereJsonContains('secondary_site_ids', $siteId));
    }

    /** Recorded incident measures retain source authority through actual aggregation. */
    private function incidentOverviewSnapshot(User $viewer, ?array $siteIds, Carbon $now, HealthSafetyReadContext $context): array
    {
        return HealthSafetyReadMaterialisationProof::within($context, function (HealthSafetyReadMaterialisationProof $proof) use ($viewer, $siteIds, $now, $context): array {
            $incidents = ClientIncident::query()->where('occurred_at', '>=', $now->copy()->subDays(30));
            $this->applySiteIds($incidents, $siteIds);
            $incidents = $proof->protect($context->applyIncidentSourceScope($incidents));

            $trends = ClientIncident::query()->where('occurred_at', '>=', $now->copy()->subMonths(12)->startOfMonth());
            $this->applySiteIds($trends, $siteIds);
            $trends = $proof->protect($context->applyIncidentSourceScope($trends))
                ->select(DB::raw("DATE_FORMAT(occurred_at, '%Y-%m') as month"), 'type', DB::raw('COUNT(*) as count'))
                ->groupBy('month', 'type')->orderBy('month')->orderBy('type');

            $events = HsEvent::query()->where('worksafe_notifiable', true);
            $this->applySiteIds($events, $siteIds);
            $events = $proof->protect(app(HsEventAccessService::class)->applyReadableScope($events, $viewer, $context))
                ->orderByDesc('occurred_at')->orderByDesc('id');

            return [
                'incidents_30d' => (clone $incidents)->count(),
                'near_misses_30d' => (clone $incidents)->where('type', 'near_miss')->count(),
                'last_notifiable' => $events->value('occurred_at'),
                'trends' => $trends->get()->groupBy('month')->map(function ($items, $month): array {
                    $types = [];
                    $total = 0;
                    foreach ($items as $item) {
                        $types[$item->type] = (int) $item->count;
                        $total += (int) $item->count;
                    }

                    return ['month' => $month, 'count' => $total, 'types' => $types];
                })->values()->all(),
            ];
        });
    }

    private function sourceReadPermissions(): array
    {
        return array_values(array_unique([
            ...DashboardClientOptionsReadService::PERMISSIONS,
            ...\App\Services\HealthSafety\FirstAidAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\RestraintAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\InjuryAccessService::PERMISSIONS,
            ...FleetIncidentReadScope::PERMISSIONS,
            'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
        ]));
    }

    /** @param array<int, int>|null $siteIds */
    private function applySiteIds(Builder $query, ?array $siteIds, string $column = 'site_id'): Builder
    {
        if ($siteIds === null) {
            return $query;
        }

        return $siteIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn($column), $siteIds);
    }

    private function applicableProcedureQuery(int|array|null $siteScope): Builder
    {
        $query = SafeWorkProcedure::query()->published();
        if ($siteScope === null) {
            return $query;
        }

        $siteIds = collect(is_array($siteScope) ? $siteScope : [$siteScope])
            ->map(fn ($siteId) => (int) $siteId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $query->whereHas('publishedVersion', fn (Builder $version) => $version->where(function (Builder $scope) use ($siteIds): void {
            $scope->whereJsonLength('content_snapshot->applicable_sites', 0)
                ->orWhereNull('content_snapshot->applicable_sites');
            foreach ($siteIds as $siteId) {
                $scope->orWhereJsonContains('content_snapshot->applicable_sites', $siteId);
            }
        }));
    }
}
