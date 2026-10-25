<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\SafetyDataSheet;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\HealthSafety\QueryPaging;

/**
 * Builds the Health & Safety Analytics payload — trend / root-cause /
 * governance series for the /health-safety/analytics explorer.
 *
 * REGION SCOPE — NEW ZEALAND ONLY. Frequency metrics are LTIFR / TRIFR
 * (per 1,000,000 hours, the NZ/AU convention) — never the US "TRIR".
 * Frameworks: WorkSafe NZ, HSWA 2015, WorkSafe notifiable events,
 * Nga Paerewa NZS 8134:2021, ACC.
 *
 * Data sources are verified in docs/HEALTH_SAFETY_ANALYTICS_BACKEND_AUDIT.md.
 * No invented schema — every series maps to a real column.
 *
 * Frequency rates (LTIFR/TRIFR), the leading-vs-lagging scorecard and the
 * hours-worked basis are delegated to {@see HsKpiService} so this page and the
 * H&S dashboard report identical numbers (same BillingEntry hours denominator
 * + the MIN_RATE_HOURS floor that prevents one injury extrapolating to a
 * six-figure rate; rates return null — UI shows "—" — below that floor). This
 * service owns the analytics-only series: monthly trends, root-cause Pareto,
 * hazard burn-down, breakdowns, the site league and CSV/JSON exports.
 */
class HsAnalyticsService
{
    private ?InjurySourceProjectionService $injurySources = null;
    public function __construct(
        private readonly HsKpiService $kpi,
        private readonly FirstAidMetricsService $firstAidMetrics,
        private readonly WorkerParticipationMetricsReadService $participationMetrics,
    ) {}

    /**
     * @param  int|null  $siteId  null = all sites
     * @param  string  $lens  governance|manager|frontline
     * @return array<string,mixed>
     */
    public function build(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, string $lens, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $from, $to, $lens) {
            $from = $from->copy()->startOfDay();
            $to = $to->copy()->endOfDay();

            // Trends always span a rolling 12-month base (or the window if longer)
            // so the charts stay readable even for a 30-day KPI window.
            $trendFrom = $from->copy()->startOfMonth()->min($to->copy()->subMonths(11)->startOfMonth());
            $months = $this->monthsBetween($trendFrom, $to);

            // LTIFR/TRIFR come from HsKpiService (shared with the dashboard). Map
            // its rolling 12-month series onto our month list by 'Y-m' key.
            $freq = collect($this->kpi->monthlyFrequencyRates(count($months), $siteId, $viewer, $context))->keyBy('month');
            $trends = $this->buildTrends($siteId, $trendFrom, $to, $months, $freq, $viewer, $context);

            $heroStats = $this->heroStats($siteId, $from, $to, $trends, $viewer, $context);
            $scorecard = $this->scorecard($siteId, $from, $to, $trends, $viewer, $context);

            $firstAid = $this->firstAidMetrics->forViewer($viewer, $from, $to, $siteId, $context);

            return [
                'incident_data' => $this->incidentsByType($siteId, $from, $to, $viewer, $context),
                'severity_data' => $this->incidentsBySeverity($siteId, $from, $to, $viewer, $context),
                'root_cause_data' => $this->rootCausePareto($siteId, $from, $to, $viewer, $context),
                'injury_data' => $this->injuryBreakdowns($siteId, $from, $to, $viewer, $context),
                'first_aid_data' => [
                    'by_type' => $firstAid['by_type'],
                    'by_outcome' => $firstAid['by_outcome'],
                    'source_access' => $firstAid['source_access'],
                    'scope' => $firstAid['scope'],
                ],
                'hazard_data' => $this->hazardsByRisk($siteId),
                'site_comparison' => $this->siteComparison($from, $to, $siteId, $viewer, $context),
                'trends' => $trends,
                'hero_stats' => $heroStats,
                'scorecard' => $scorecard,
                'period_summary' => $this->periodSummary($siteId, $from, $to, $viewer, $context),
                'worksafe_notifiable' => $this->worksafeTotals($siteId, $from, $to, $viewer, $context),
                'sds_expiring' => $this->sdsExpiringCount($siteId),
                'hours_meta' => [
                    'source' => 'billing_entries',
                    'total_hours' => round($this->kpi->totalHoursWorked(null, null, $siteId), 0),
                ],
                'role_note' => $this->roleNote($lens),
            ];
        });
    }

    /**
     * Current Safety Data Sheets due for review within 30 days (incl. overdue),
     * site-scoped via the substance's storage locations. Single source shared
     * with the register hero and the dashboard expiring feed.
     */
    private function sdsExpiringCount(int|array|null $siteId): int
    {
        return SafetyDataSheet::query()
            ->expiringWithin(30)
            ->when($siteId !== null, fn ($q) => $q->whereHas(
                'hazardousSubstance.storageLocations',
                fn ($locationQuery) => $this->applySiteScope($locationQuery, $siteId),
            ))
            ->count();
    }

    // ── Monthly trend series ────────────────────────────────────────────

    /**
     * @param  array<int,string>  $months
     * @param  Collection<string,array{month:string,ltifr:float|null,trifr:float|null}>  $freq
     * @return array<int,array<string,mixed>>
     */
    private function buildTrends(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, array $months, Collection $freq, ?User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        $incidents = $this->incidentCountsByMonth($siteId, $from, $to, $viewer, $context);
        $hazOpened = $this->hazardOpenedByMonth($siteId, $from, $to);
        $hazClosed = $this->hazardClosedByMonth($siteId, $from, $to);
        $runningOpen = $this->hazardRunningOpen($siteId, $from, $months, $hazOpened, $hazClosed);

        $ca = $this->correctiveActionByMonth($siteId, $from, $to, $viewer, $context);
        $compliance = $this->complianceByMonth($months, $siteId);
        $participation = $this->participationMetrics->monthly($viewer, $from, $to, $siteId, $context, true);
        $engagement = $participation['engagement'];
        $consultation = $participation['consultation'];
        $worksafe = $this->worksafeByMonth($siteId, $from, $to, $viewer, $context);

        return collect($months)->map(function (string $m) use (
            $freq, $incidents, $hazOpened, $hazClosed,
            $runningOpen, $ca, $compliance, $engagement, $consultation, $worksafe, $participation
        ) {
            $inc = $incidents[$m] ?? ['total' => 0, 'near_miss' => 0];
            $nm = $inc['near_miss'];
            $other = max($inc['total'] - $nm, 0);
            $rates = $freq->get($m) ?? [];

            return [
                'month' => $m,
                'label' => Carbon::parse($m.'-01')->format('M y'),
                'ltifr' => $rates['ltifr'] ?? null,
                'trifr' => $rates['trifr'] ?? null,
                'incidents' => $inc['total'],
                'near_miss_ratio' => $other > 0 ? round($nm / $other, 1) : ($nm > 0 ? (float) $nm : 0.0),
                'hazards_opened' => $hazOpened[$m] ?? 0,
                'hazards_closed' => $hazClosed[$m] ?? 0,
                'hazards_open' => $runningOpen[$m] ?? 0,
                'ca_avg_days' => $ca[$m]['avg_days'] ?? null,
                'ca_pct_on_time' => $ca[$m]['pct_on_time'] ?? null,
                'compliance_pct' => $compliance[$m] ?? null,
                'worker_engagement' => $engagement[$m] ?? null,
                'worker_consultation' => $consultation[$m] ?? null,
                'worker_participation_source_access' => $participation['source_access'],
                'worker_participation_viewer_id' => $participation['viewer_id'],
                'worksafe_notified' => $worksafe[$m]['notified'] ?? 0,
                'worksafe_awaiting' => $worksafe[$m]['awaiting'] ?? 0,
            ];
        })->all();
    }

    // ── Injuries ────────────────────────────────────────────────────────

    /** @return array<int,array{type:string,count:int}> */
    private function injuryBreakdowns(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer, ?HealthSafetyReadContext $context): array
    {
        $query = WorkplaceInjury::query()->whereBetween('injury_date', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId));
        $result = $this->injuryMetric($query, $viewer, $context, fn (Builder $source): array => [
            'by_type' => (clone $source)->selectRaw('injury_type as type, COUNT(*) as count')->groupBy('injury_type')->orderByDesc('count')
                ->get()->map(fn ($row) => ['type' => (string) $row->type, 'count' => (int) $row->count])->all(),
            'by_body_part' => (clone $source)->whereNotNull('body_part_affected')->selectRaw('body_part_affected as body_part, COUNT(*) as count')
                ->groupBy('body_part_affected')->orderByDesc('count')->get()
                ->map(fn ($row) => ['body_part' => (string) $row->body_part, 'count' => (int) $row->count])->all(),
        ]);
        return [...($result['value'] ?? ['by_type' => [], 'by_body_part' => []]), ...$result['coverage']];
    }

    // ── Incidents ───────────────────────────────────────────────────────

    /** @return array<string,array{total:int,near_miss:int}> */
    private function incidentCountsByMonth(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $rows = ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m') as m, SUM(type = 'near_miss') as nm, COUNT(*) as total")
            ->groupBy('m')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->m] = ['total' => (int) $r->total, 'near_miss' => (int) $r->nm];
        }

        return $out;
    }

    /** @return array<int,array{type:string,count:int}> */
    private function incidentsByType(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'count' => (int) $r->count])
            ->all();
    }

    /** @return array<int,array{severity:string,count:int}> */
    private function incidentsBySeverity(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['severity' => (string) ($r->severity ?: 'unspecified'), 'count' => (int) $r->count])
            ->all();
    }

    /** Ordered desc with running cumulative % for the Pareto line. @return array<int,array<string,mixed>> */
    private function rootCausePareto(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        $counts = [];
        $this->rootCauseInvestigations($siteId, $from, $to, $viewer, $context)->each(function (HsInvestigation $investigation) use (&$counts) {
            foreach ($this->investigationRootCauseLabels($investigation) as $cause) {
                $counts[$cause] = ($counts[$cause] ?? 0) + 1;
            }
        });
        arsort($counts);

        $total = array_sum($counts) ?: 1;
        $running = 0;

        return collect($counts)->map(function (int $count, string $cause) use ($total, &$running) {
            $running += $count;

            return [
                'cause' => $cause,
                'count' => $count,
                'pct' => round($count / $total * 100, 1),
                'cumulative_pct' => round($running / $total * 100, 1),
            ];
        })->values()->all();
    }

    private function rootCauseInvestigations(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer, ?HealthSafetyReadContext $context = null): Collection
    {
        $query = HsInvestigation::query()
            ->with('hsEvent:id,site_id,occurred_at')
            ->whereNotNull('root_causes')
            ->whereHas('hsEvent', fn (Builder $event) => $event
                ->whereBetween('occurred_at', [$from, $to])
                ->when($siteId !== null, fn (Builder $eventQuery) => $this->applySiteScope($eventQuery, $siteId)));
        $this->applyRelatedSourceScope($query, $viewer, $context);

        return $this->protectSourceEagerLoads($query)->get(['id', 'hs_event_id', 'root_causes']);
    }

    /** @return array<int,string> */
    private function investigationRootCauseLabels(HsInvestigation $investigation): array
    {
        $labels = [];
        foreach ($investigation->root_causes ?? [] as $rootCause) {
            if (! is_array($rootCause)) {
                continue;
            }

            $label = trim((string) ($rootCause['category'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($rootCause['description'] ?? ''));
            }
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return array<int,int> */
    private function incidentIdsForRootCause(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, string $cause, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $cause = trim($cause);
        if ($cause === '') {
            return [];
        }

        $query = HsInvestigation::query()
            ->with('hsEvent:id,source_type,source_id,site_id,occurred_at')
            ->whereNotNull('root_causes')
            ->whereHas('hsEvent', fn ($q) => $q
                ->where('source_type', ClientIncident::class)
                ->whereBetween('occurred_at', [$from, $to])
                ->when($siteId !== null, fn ($eventQuery) => $this->applySiteScope($eventQuery, $siteId)));
        $this->applyRelatedSourceScope($query, $viewer, $context);

        return $this->protectSourceEagerLoads($query)->get(['id', 'hs_event_id', 'root_causes'])
            ->filter(fn (HsInvestigation $investigation) => in_array($cause, $this->investigationRootCauseLabels($investigation), true))
            ->map(fn (HsInvestigation $investigation) => (int) $investigation->hsEvent?->source_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,int>  $incidentIds
     * @return array<int,array<int,string>>
     */
    private function rootCauseLabelsByIncidentId(array $incidentIds, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $incidentIds = array_values(array_unique(array_filter($incidentIds)));
        if ($incidentIds === []) {
            return [];
        }

        $labelsByIncident = [];
        $query = HsInvestigation::query()
            ->with('hsEvent:id,source_type,source_id')
            ->whereNotNull('root_causes')
            ->whereHas('hsEvent', fn ($q) => $q
                ->where('source_type', ClientIncident::class)
                ->whereIn('source_id', $incidentIds));
        $this->applyRelatedSourceScope($query, $viewer, $context);
        $this->protectSourceEagerLoads($query)->get(['id', 'hs_event_id', 'root_causes'])
            ->each(function (HsInvestigation $investigation) use (&$labelsByIncident) {
                $incidentId = (int) $investigation->hsEvent?->source_id;
                if (! $incidentId) {
                    return;
                }

                foreach ($this->investigationRootCauseLabels($investigation) as $label) {
                    $labelsByIncident[$incidentId][] = $label;
                }
                $labelsByIncident[$incidentId] = array_values(array_unique($labelsByIncident[$incidentId] ?? []));
            });

        return $labelsByIncident;
    }

    // ── Hazards ─────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function hazardOpenedByMonth(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return SiteHazard::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @return array<string,int> */
    private function hazardClosedByMonth(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return SiteHazard::query()
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->selectRaw("DATE_FORMAT(closed_at, '%Y-%m') as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Running count of currently-open hazards at each month end:
     * baseline (open before window) + cumulative(opened − closed).
     *
     * @param  array<int,string>  $months
     * @param  array<string,int>  $opened
     * @param  array<string,int>  $closed
     * @return array<string,int>
     */
    private function hazardRunningOpen(int|array|null $siteId, CarbonInterface $from, array $months, array $opened, array $closed): array
    {
        $baseline = SiteHazard::query()
            ->where('created_at', '<', $from)
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $from))
            ->count();

        $out = [];
        $running = $baseline;
        foreach ($months as $m) {
            $running += ($opened[$m] ?? 0) - ($closed[$m] ?? 0);
            $out[$m] = max($running, 0);
        }

        return $out;
    }

    /** @return array<int,array{risk_rating:string,count:int}> open hazards by risk */
    private function hazardsByRisk(int|array|null $siteId): array
    {
        return SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->selectRaw('risk_rating, COUNT(*) as count')
            ->groupBy('risk_rating')
            ->get()
            ->map(fn ($r) => ['risk_rating' => (string) $r->risk_rating, 'count' => (int) $r->count])
            ->all();
    }

    // ── Corrective actions ──────────────────────────────────────────────

    /** @return array<string,array{avg_days:float|null,pct_on_time:float|null}> */
    private function correctiveActionByMonth(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        $base = HsCorrectiveAction::query()

            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->when($siteId !== null, fn ($query) => $query->whereHas(
                'hsEvent',
                fn ($eventQuery) => $this->applySiteScope($eventQuery, $siteId),
            ));
        $this->applyRelatedSourceScope($base, $viewer, $context);
        $rows = $base
            ->selectRaw("DATE_FORMAT(completed_at, '%Y-%m') as m")
            ->selectRaw('AVG(DATEDIFF(completed_at, created_at)) as avg_days')
            ->selectRaw('SUM(CASE WHEN due_date IS NOT NULL AND DATE(completed_at) <= due_date THEN 1 ELSE 0 END) as on_time')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('m')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->m] = [
                'avg_days' => $r->avg_days !== null ? round((float) $r->avg_days, 1) : null,
                'pct_on_time' => (int) $r->total > 0 ? round((int) $r->on_time / (int) $r->total * 100) : null,
            ];
        }

        return $out;
    }

    // ── Training & audit compliance % ───────────────────────────────────

    /**
     * % of training records valid (completed/passed & unexpired) as of each
     * month end. One query + in-memory as-of computation.
     *
     * @param  array<int,string>  $months
     * @return array<string,int|null>
     */
    private function complianceByMonth(array $months, int|array|null $siteId): array
    {
        $siteIds = $siteId === null ? [] : $this->normalizeSiteIds($siteId);
        $records = StaffTrainingRecord::query()
            ->when($siteId !== null, fn ($query) => $query->whereHas(
                'user.hrEmployeeProfile',
                function ($profileQuery) use ($siteIds): void {
                    $profileQuery->whereIn('primary_site_id', $siteIds);
                    foreach ($siteIds as $id) {
                        $profileQuery->orWhereJsonContains('secondary_site_ids', $id);
                    }
                },
            ))
            ->get(['status', 'enrolled_at', 'completed_at', 'completion_date', 'expires_at']);

        $out = [];
        foreach ($months as $m) {
            $end = Carbon::parse($m.'-01')->endOfMonth();

            $denom = $records->filter(fn ($r) => $r->enrolled_at === null || $r->enrolled_at <= $end)->count();
            if ($denom === 0) {
                $out[$m] = null;

                continue;
            }

            $valid = $records->filter(function ($r) use ($end) {
                if (! in_array((string) $r->status, ['completed', 'passed'], true)) {
                    return false;
                }
                $done = $r->completion_date ?? $r->completed_at;
                if ($done !== null && $done > $end) {
                    return false;
                }

                return $r->expires_at === null || $r->expires_at >= $end;
            })->count();

            $out[$m] = (int) round($valid / $denom * 100);
        }

        return $out;
    }

    // ── WorkSafe notifiable (HSWA s.56) ─────────────────────────────────

    /** @return array<string,array{notified:int,awaiting:int}> */
    private function worksafeByMonth(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $rows = NotifiableIncident::query()
            ->where('notification_authority', 'worksafe')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, $this->worksafeSiteScope($siteId, $viewer, $context))
            ->tap(fn (Builder $query) => $this->notifiableIncidentSourceScope($query, $viewer, $context))
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m') as m, SUM(status = 'pending') as awaiting, SUM(status <> 'pending') as notified")
            ->groupBy('m')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->m] = ['notified' => (int) $r->notified, 'awaiting' => (int) $r->awaiting];
        }

        return $out;
    }

    /** @return array{notified:int,awaiting:int} */
    private function worksafeTotals(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $scope = $this->worksafeSiteScope($siteId, $viewer, $context);

        return [
            'notified' => (int) NotifiableIncident::query()
                ->where('notification_authority', 'worksafe')
                ->where('status', '!=', 'pending')
                ->whereBetween('occurred_at', [$from, $to])
                ->when($siteId !== null, $scope)
                ->tap(fn (Builder $query) => $this->notifiableIncidentSourceScope($query, $viewer, $context))
                ->count(),
            // Awaiting is a live state — not window-bound.
            'awaiting' => (int) NotifiableIncident::query()
                ->where('notification_authority', 'worksafe')
                ->where('status', 'pending')
                ->when($siteId !== null, $scope)
                ->tap(fn (Builder $query) => $this->notifiableIncidentSourceScope($query, $viewer, $context))
                ->count(),
        ];
    }

    /**
     * WorkSafe notification is an org-level PCBU obligation. When a site is
     * selected we attribute an event via its linked incident-time site snapshot;
     * events with no linked incident can't be site-attributed, so they drop
     * out of the site-scoped view (and only appear org-wide). NotifiableIncident
     * has no site_id, hence the relatedIncident relationship.
     */
    private function worksafeSiteScope(int|array|null $siteId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): \Closure
    {
        return fn ($q) => $q->whereHas(
            'relatedIncident',
            fn ($incidentQuery) => $this->applySiteScope($incidentQuery, $siteId),
        );
    }

    // ── Site league + heatmap (the site-scoping bug fix) ────────────────

    /** @return array<int,array<string,mixed>> */
    private function siteComparison(CarbonInterface $from, CarbonInterface $to, int|array|null $siteId = null, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $incidentsBySite = ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('site_id')
            ->when($siteId !== null, fn ($query) => $this->applySiteScope($query, $siteId))
            ->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) as c')
            ->pluck('c', 'site_id');

        $openHazardsBySite = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->when($siteId !== null, fn ($query) => $this->applySiteScope($query, $siteId))
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) as c')
            ->pluck('c', 'site_id');

        $lostDaysQuery = WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId !== null, fn ($query) => $this->applySiteScope($query, $siteId));
        $lostDaysResult = $this->injuryMetric($lostDaysQuery, $viewer, $context,
            fn (Builder $source): array => $source->groupBy('site_id')->selectRaw('site_id, SUM(lost_time_days) as d')->pluck('d', 'site_id')->all());
        $lostDaysBySite = $lostDaysResult['value'];

        $drillBySite = $this->drillStatusBySite($siteId);

        return Site::query()
            ->when($siteId !== null, fn ($query) => $this->applySiteScope($query, $siteId, 'id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($site) use (
                $incidentsBySite, $openHazardsBySite, $lostDaysBySite, $drillBySite, $lostDaysResult, $viewer, $context
            ) {
                $incidents = (int) ($incidentsBySite[$site->id] ?? 0);
                $openHazards = (int) ($openHazardsBySite[$site->id] ?? 0);
                $drillStatus = $drillBySite[$site->id] ?? 'overdue';

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'total_incidents' => $incidents,
                    'open_hazards' => $openHazards,
                    'lost_time_days' => $lostDaysBySite === null || ($lostDaysResult['coverage']['access'] === 'repair_required' && ! isset($lostDaysBySite[$site->id])) ? null : (int) ($lostDaysBySite[$site->id] ?? 0),
                    'injury_source_access' => $lostDaysResult['coverage']['access'],
                    // Per-site LTIFR/TRIFR from HsKpiService (12-month annualised
                    // basis, BillingEntry hours, floored) — identical to the dashboard.
                    'ltifr' => $this->kpi->ltifr(null, null, $site->id, $viewer, $context),
                    'trifr' => $this->kpi->trifr(null, null, $site->id, $viewer, $context),
                    'drill_status' => $drillStatus,
                    'compliance_score' => null,
                    'source_scope' => 'readable_subset',
                    'source_completeness' => 'unknown',
                ];
            })->all();
    }

    /**
     * @return array<int,string> site_id => compliant|due_soon|overdue
     *
     * Delegates to {@see DrillComplianceService} — the single source of truth that
     * the register hero, dashboard KPI and site profile also use (so the numbers
     * reconcile across every surface).
     */
    private function drillStatusBySite(int|array|null $siteId = null): array
    {
        return app(DrillComplianceService::class)->statusBySite($siteId);
    }

    // ── Hero stats + scorecard ──────────────────────────────────────────

    /**
     * @param  array<int,array<string,mixed>>  $trends
     * @return array<string,mixed>
     */
    private function heroStats(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, array $trends, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        // Headline frequency/leading numbers come from HsKpiService so they
        // match the dashboard exactly (12-month annualised basis + floor); the
        // delta is the month-over-month direction from our own trend series.
        return [
            'ltifr' => array_merge(
                ['value' => $this->kpi->ltifr(null, null, $siteId, $viewer, $context)],
                $this->deltaFor($trends, 'ltifr', lowerIsBetter: true)
            ),
            'trifr' => array_merge(
                ['value' => $this->kpi->trifr(null, null, $siteId, $viewer, $context)],
                $this->deltaFor($trends, 'trifr', lowerIsBetter: true)
            ),
            'near_miss_ratio' => array_merge(
                ['value' => $this->kpi->nearMissToIncidentRatio($from, $to, $siteId, $viewer, $context)],
                $this->deltaFor($trends, 'near_miss_ratio', lowerIsBetter: false)
            ),
            'compliance_pct' => array_merge(
                ['value' => $this->kpi->trainingAuditCompliancePct($siteId)],
                $this->deltaFor($trends, 'compliance_pct', lowerIsBetter: false)
            ),
        ];
    }

    /**
     * Delta of the last vs previous non-null month for a trend key.
     *
     * @param  array<int,array<string,mixed>>  $trends
     * @return array{delta:float|null,dir:string}
     */
    private function deltaFor(array $trends, string $key, bool $lowerIsBetter): array
    {
        $vals = collect($trends)->pluck($key)->filter(fn ($v) => $v !== null)->values();
        if ($vals->count() < 2) {
            return ['delta' => null, 'dir' => 'flat'];
        }

        $last = (float) $vals->last();
        $prev = (float) $vals->get($vals->count() - 2);
        $delta = round($last - $prev, 1);

        if ($delta === 0.0) {
            return ['delta' => 0.0, 'dir' => 'flat'];
        }

        $improving = $lowerIsBetter ? $delta < 0 : $delta > 0;

        return ['delta' => $delta, 'dir' => $improving ? 'improving' : 'watch'];
    }

    /**
     * Leading-vs-lagging scorecard (board-ready). The shared numbers come from
     * HsKpiService->leadingLagging() so they match the dashboard exactly; the
     * month-over-month delta direction comes from our own trend series.
     *
     * @param  array<int,array<string,mixed>>  $trends
     * @return array{leading:array<int,array<string,mixed>>,lagging:array<int,array<string,mixed>>}
     */
    private function scorecard(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, array $trends, ?User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        $ll = $this->kpi->leadingLagging($from, $to, $siteId, $viewer, $context);
        $lag = $ll['lagging'];
        $lead = $ll['leading'];
        $latest = collect($trends)->last() ?? [];

        $injuryOperands = $this->kpi->workplaceInjuryOperands($from, $to, $siteId, $viewer, $context);
        $lostDays = $injuryOperands['lost_time_days'];

        $d = fn (string $key, bool $lower): array => $this->deltaFor($trends, $key, $lower);

        $leading = [
            ['key' => 'near_miss_ratio', 'label' => 'Near-miss reporting ratio', 'value' => $lead['near_miss_ratio'], 'suffix' => ':1', ...$d('near_miss_ratio', false)],
            ['key' => 'actions_on_time_pct', 'label' => 'Corrective actions on time', 'value' => $lead['actions_on_time_pct'], 'suffix' => '%', ...$d('ca_pct_on_time', false)],
            ['key' => 'training_pct', 'label' => 'Training & audit compliance', 'value' => $lead['training_pct'], 'suffix' => '%', ...$d('compliance_pct', false)],
            ['key' => 'worker_engagement', 'label' => 'Meetings completed', 'value' => $latest['worker_engagement'] ?? null, 'source_access' => $latest['worker_participation_source_access'] ?? 'unavailable', 'viewer_id' => $latest['worker_participation_viewer_id'] ?? null, 'suffix' => '%', ...$d('worker_engagement', false)],
            ['key' => 'open_hazards', 'label' => 'Open hazards', 'value' => $lead['open_hazards'], 'suffix' => '', ...$d('hazards_open', true)],
            ['key' => 'worker_consultation', 'label' => 'Consultation completion', 'value' => $latest['worker_consultation'] ?? null, 'source_access' => $latest['worker_participation_source_access'] ?? 'unavailable', 'viewer_id' => $latest['worker_participation_viewer_id'] ?? null, 'suffix' => '%', ...$d('worker_consultation', false)],
            ['key' => 'procedure_review_pct', 'label' => 'Procedure review compliance', 'value' => $this->procedureReviewPct($siteId), 'suffix' => '%', 'delta' => null, 'dir' => 'flat'],
        ];

        $lagging = [
            ['key' => 'ltifr', 'label' => 'LTIFR', 'value' => $lag['ltifr'], 'suffix' => '', ...$d('ltifr', true)],
            ['key' => 'trifr', 'label' => 'TRIFR', 'value' => $lag['trifr'], 'suffix' => '', ...$d('trifr', true)],
            ['key' => 'incidents', 'label' => 'Incidents', 'value' => $lag['incidents'], 'suffix' => '', ...$d('incidents', true)],
            ['key' => 'injury_severity_rate', 'label' => 'Injury severity rate', 'value' => $lag['injury_severity_rate'], 'suffix' => '', 'delta' => null, 'dir' => 'flat'],
            ['key' => 'lost_time_days', 'label' => 'Lost-time days', 'value' => $lostDays, 'suffix' => '', 'delta' => null, 'dir' => 'flat'],
            ['key' => 'days_since_lti', 'label' => 'Days since last LTI', 'value' => $lag['days_since_lti'], 'suffix' => 'd', 'delta' => null, 'dir' => 'flat'],
        ];

        return ['leading' => $leading, 'lagging' => $lagging];
    }

    /**
     * % of approved Safe Work Procedures still within their review date (or with no
     * date set) — a leading control-of-documents indicator. Site views include
     * procedures explicitly assigned to the site plus organisation-wide procedures.
     */
    private function procedureReviewPct(int|array|null $siteId): ?int
    {
        $approvedQuery = $this->applicableProcedureQuery($siteId);
        $approved = (clone $approvedQuery)->count();
        if ($approved === 0) {
            return null;
        }

        $inWindow = (clone $approvedQuery)
            ->whereNotNull('published_review_date')->where('published_review_date', '>=', now(\App\Support\Incidents\IncidentOccurrencePeriod::timezone())->toDateString())
            ->count();

        return (int) round($inWindow / $approved * 100);
    }

    // ── Period summary + role note ──────────────────────────────────────

    /** @return array<string,mixed> */
    private function periodSummary(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, ?User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        $incidents = (int) ClientIncident::query()->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))->count();
        $nearMisses = (int) ClientIncident::query()->where('type', 'near_miss')->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))->count();
        $openHazards = (int) SiteHazard::query()->whereIn('status', ['open', 'in_progress'])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))->count();

        // Corrective-action on-time % via HsKpiService — matches the scorecard
        // and is site-scoped (through the parent HsEvent).
        $actionsOnTime = $this->kpi->actionsClosedOnTimePct($from, $to, $siteId, $viewer, $context);

        // Drills are a per-site governance metric — scope to the selected site.
        $drillStatuses = $this->drillStatusBySite($siteId);
        if ($siteId !== null) {
            $siteIds = $this->normalizeSiteIds($siteId);
            $drillsTotal = count($siteIds);
            $drillsComplete = count(array_filter(
                $siteIds,
                fn (int $id) => ($drillStatuses[$id] ?? null) === 'compliant',
            ));
        } else {
            $drillsTotal = (int) Site::query()->count();
            $drillsComplete = count(array_filter($drillStatuses, fn ($s) => $s === 'compliant'));
        }

        return [
            'incidents' => $incidents,
            'near_misses' => $nearMisses,
            'worksafe_awaiting' => $this->worksafeTotals($siteId, $from, $to, $viewer, $context)['awaiting'],
            'open_hazards' => $openHazards,
            'actions_on_time_pct' => $actionsOnTime === null ? null : (int) round($actionsOnTime),
            'drills_complete' => $drillsComplete,
            'drills_total' => $drillsTotal,
        ];
    }

    private function roleNote(string $lens): string
    {
        return match ($lens) {
            'governance' => 'Board lens — leading-vs-lagging assurance, WorkSafe notifiable status and corrective-action traceability foregrounded.',
            'frontline' => 'Frontline lens — hazards, near-miss reporting and site-level activity foregrounded for day-to-day safety.',
            default => 'Manager lens — site performance, trends and corrective-action closure foregrounded for operational oversight.',
        };
    }

    // ── CSV export (record-level, mirrors the drill-in lists) ───────────

    /**
     * Rows for a CSV export / drill-in records list of the active view.
     * Read-only register records, site- and range-scoped to match the page,
     * plus optional drill sub-filters (type/severity/cause/risk/body_part).
     *
     * @param  array<string,mixed>  $filters
     * @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
     */
    public function exportRows(string $view, int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, array $filters = [], ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($view, $siteId, $from, $to, $filters) {
            $from = $from->copy()->startOfDay();
            $to = $to->copy()->endOfDay();
            $siteNames = Site::query()->pluck('name', 'id');

            return match ($view) {
                'injuries' => $this->exportInjuries($siteId, $from, $to, $siteNames, $filters, $viewer, $context),
                'hazards' => $this->exportHazards($siteId, $from, $to, $siteNames, $filters),
                'sites' => $this->exportSites($from, $to, $siteId, $viewer, $context),
                'root_cause' => [
                    'name' => 'root_cause',
                    'headers' => ['Cause', 'Count', '% of total', 'Cumulative %'],
                    'rows' => array_map(
                        fn ($r) => [$r['cause'], $r['count'], $r['pct'], $r['cumulative_pct']],
                        $this->rootCausePareto($siteId, $from, $to, $viewer, $context)
                    ),
                ],
                default => $this->exportIncidents($siteId, $from, $to, $siteNames, $filters, $viewer, $context),
            };
        });
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
     */
    private function exportIncidents(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, Collection $siteNames, array $filters = [], ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $incidentIdsForCause = ! empty($filters['cause'])
            ? $this->incidentIdsForRootCause($siteId, $from, $to, (string) $filters['cause'], $viewer, $context)
            : null;

        $query = ClientIncident::query()
            ->with('client:id,first_name,last_name,site_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->when(! empty($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(! empty($filters['severity']), fn ($q) => $q->where('severity', $filters['severity']))
            ->when($incidentIdsForCause !== null, fn ($q) => $q->whereIn('id', $incidentIdsForCause ?: [0]))
            ->tap(fn (Builder $query) => $this->incidentSourceQuery($query, $viewer, $context))
            ->orderByDesc('occurred_at');
        $rows = $this->protectSourceEagerLoads($query)->get();
        $rootCausesByIncident = $this->rootCauseLabelsByIncidentId($rows->pluck('id')->map(fn ($id) => (int) $id)->all(), $viewer, $context);

        return [
            'name' => 'incidents',
            'headers' => ['ID', 'Occurred', 'Type', 'Severity', 'Status', 'Site', 'Client', 'Root cause'],
            'rows' => $rows->map(fn ($i) => [
                $i->id,
                optional($i->occurred_at)->toDateString(),
                $i->type,
                $i->severity,
                $i->status,
                $siteNames[$i->site_id] ?? '—',
                trim(($i->client?->first_name ?? '').' '.($i->client?->last_name ?? '')) ?: '—',
                implode(', ', $rootCausesByIncident[(int) $i->id] ?? []) ?: '—',
            ])->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
     */
    private function exportInjuries(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, Collection $siteNames, array $filters = [], ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $query = WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->when(! empty($filters['type']), fn ($q) => $q->where('injury_type', $filters['type']))
            ->when(! empty($filters['body_part']), fn ($q) => $q->where('body_part_affected', $filters['body_part']))
            ->orderByDesc('injury_date');
        $result = $this->injuryMetric($query, $viewer, $context, fn (Builder $source): array => $source->get(InjuryAccessService::FACTUAL_COLUMNS)->map(fn ($i) => [
            $i->id, optional($i->injury_date)->toDateString(), $i->injury_type, $i->body_part_affected ?? '—', $i->severity,
            (int) $i->lost_time_days, $siteNames[$i->site_id] ?? '—', $i->worksafe_notifiable ? 'Yes' : 'No', $i->acc_claim_lodged ? 'Yes' : 'No',
        ])->all());

        return [
            'name' => 'injuries',
            'headers' => ['ID', 'Date', 'Type', 'Body part', 'Severity', 'Lost-time days', 'Site', 'WorkSafe notifiable', 'ACC claim'],
            'rows' => $result['value'] ?? [],
            ...$result['coverage'],
        ];
    }

    private function injuryMetric(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context, callable $consume): array
    {
        $restricted = ['value' => null, 'coverage' => ['access' => 'restricted', 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown', 'attribution_state' => 'unavailable']];
        if ($viewer === null || $context === null) { return $restricted; }
        $context->assertViewer($viewer);
        try {
            return ($this->injurySources ??= app(InjurySourceProjectionService::class))->consumeMetricQueryInContext($context, $query, 'factual',
                fn (?Builder $source, array $coverage): array => ['value' => $source === null ? null : $consume(clone $source), 'coverage' => $coverage]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $denial) {
            if ($denial->getStatusCode() !== 403) { throw $denial; }
            return $restricted;
        }
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
     */
    private function exportHazards(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to, Collection $siteNames, array $filters = []): array
    {
        $rows = SiteHazard::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $this->applySiteScope($q, $siteId))
            ->when(! empty($filters['risk']), fn ($q) => $q->where('risk_rating', $filters['risk']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->get();

        return [
            'name' => 'hazards',
            'headers' => ['ID', 'Opened', 'Type', 'Risk rating', 'Status', 'Site', 'Due', 'Closed'],
            'rows' => $rows->map(fn ($h) => [
                $h->id,
                optional($h->created_at)->toDateString(),
                $h->hazard_type,
                $h->risk_rating,
                $h->status,
                $siteNames[$h->site_id] ?? '—',
                optional($h->due_date)->toDateString() ?? '—',
                optional($h->closed_at)->toDateString() ?? '—',
            ])->all(),
        ];
    }

    /** @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>} */
    private function exportSites(CarbonInterface $from, CarbonInterface $to, int|array|null $siteId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return [
            'name' => 'site_league',
            'headers' => ['Site', 'Incidents', 'Open hazards', 'Lost-time days', 'LTIFR', 'TRIFR', 'Compliance %', 'Drill status'],
            'rows' => array_map(fn ($s) => [
                $s['name'],
                $s['total_incidents'],
                $s['open_hazards'],
                $s['lost_time_days'],
                $s['ltifr'] ?? '—',
                $s['trifr'] ?? '—',
                $s['compliance_score'],
                $s['drill_status'],
            ], $this->siteComparison($from, $to, $siteId, $viewer, $context)),
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function applySiteScope(Builder $query, int|array|null $siteId, string $column = 'site_id'): Builder
    {
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);

        return $siteIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn($column), $siteIds);
    }

    private function applicableProcedureQuery(int|array|null $siteId): Builder
    {
        $query = SafeWorkProcedure::query()->published();
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);

        return $query->whereHas('publishedVersion', fn (Builder $version) => $version->where(function (Builder $scope) use ($siteIds): void {
            $scope->whereJsonLength('content_snapshot->applicable_sites', 0)
                ->orWhereNull('content_snapshot->applicable_sites');

            foreach ($siteIds as $id) {
                $scope->orWhereJsonContains('content_snapshot->applicable_sites', $id);
            }
        }));
    }

    /** @return array<int, int> */
    private function normalizeSiteIds(int|array $siteId): array
    {
        return collect(is_array($siteId) ? $siteId : [$siteId])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int,string> e.g. ['2025-07', …, '2026-06'] */
    private function monthsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $months;
    }

    /** Source proof uses an executable filtered child subquery, never the correlated relation builder. */
    private function applyRelatedSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $children = (clone $query)->withoutEagerLoads()->reorder();
        $children->getQuery()->limit = null;
        $children->getQuery()->offset = null;
        $candidates = HsEvent::query()->whereIn('hs_events.id', $children->select($query->getModel()->qualifyColumn('hs_event_id')));

        return $this->protectMaterialisation($query->whereHas('hsEvent', fn (Builder $event) => app(HsEventAccessService::class)->applyFirstAidSourceScope($event, $viewer, $context, $candidates)));
    }

    /** All source decisions and their final SQL share the caller's original prepared evidence. */
    private ?HealthSafetyReadMaterialisationProof $materialisationProof = null;

    private function protectMaterialisation(Builder $query): Builder
    {
        return $this->materialisationProof?->protect($query) ?? $query;
    }
    private function withSourceRead(?User $viewer, ?HealthSafetyReadContext $context, callable $consumer): mixed
    {
        $project = static fn (mixed $result): mixed => is_array($result) && ! array_is_list($result)
            ? [...$result, 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown'] : $result;
        if ($viewer === null) {
            abort_unless($context === null, 403);

            return DB::transaction(fn () => $project($consumer(null, null)));
        }
        $permissions = array_values(array_unique([...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS]));
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $permissions, $consumer, $project): mixed {
            $active->assertViewer($viewer);

            return $active->consumeSourceViewer($permissions,
                function (User $current) use ($active, $project, $consumer): mixed {
                    return HealthSafetyReadMaterialisationProof::within($active, function (HealthSafetyReadMaterialisationProof $proof) use ($current, $active, $project, $consumer): mixed {
                        $previous = $this->materialisationProof;
                        $this->materialisationProof = $proof;
                        try { return $project($consumer($current, $active)); }
                        finally { $this->materialisationProof = $previous; }
                    });
                });
        };

        return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, $permissions, $consume);
    }

    private function incidentSourceQuery(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context): Builder
    {
        if ($viewer === null || $context === null) {
            return $query->whereRaw('1 = 0');
        }
        $context->assertViewer($viewer);

        return $this->protectMaterialisation($context->applyIncidentSourceScope($query));
    }

    private function notifiableIncidentSourceScope(Builder $notifications, ?User $viewer, ?HealthSafetyReadContext $context): Builder
    {
        $original = (clone $notifications)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
        $sources = ClientIncident::query()->whereIn('client_incidents.id', $original->select('related_incident_id'));
        $parents = $this->incidentSourceQuery($sources, $viewer, $context);

        $notifications->where(fn (Builder $source) => $source->whereNull('related_incident_id')
            ->orWhereIn('related_incident_id', (clone $parents)->select('client_incidents.id')));

        return $this->reconsumeOriginalSource($notifications, $parents);
    }

    /** Eager names and event bodies retain the original admitted report query through their own SELECT. */
    private function protectSourceEagerLoads(Builder $parents): Builder
    {
        $eagerLoads = $parents->getEagerLoads();
        $columns = [
            'hsEvent' => 'hs_event_id', 'client' => 'client_id', 'staff' => 'staff_id',
            'site' => 'site_id', 'owner' => 'owner_user_id', 'assignedTo' => 'assigned_to_user_id',
            'completedBy' => 'completed_by_user_id', 'verifiedBy' => 'verified_by_user_id',
            'leadInvestigator' => 'lead_investigator_id', 'assessedBy' => 'assessed_by_user_id',
            'approvedBy' => $parents->getModel() instanceof HsInvestigation ? 'approved_by_id' : 'approved_by_user_id',
        ];
        foreach ($columns as $name => $column) {
            if (! isset($eagerLoads[$name])) {
                continue;
            }
            $original = $eagerLoads[$name];
            $proof = (clone $parents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
                ->select($parents->getModel()->qualifyColumn($column));
            $parents->with([$name => function ($relation) use ($original, $proof): void {
                $original($relation);
                $relation->whereIn($relation->getRelated()->qualifyColumn('id'), clone $proof);
                $this->reconsumeOriginalSource($relation->getQuery(), $proof);
            }]);
        }
        if (isset($eagerLoads['hsEvent.site'])) {
            $original = $eagerLoads['hsEvent.site'];
            $proof = (clone $parents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
                ->select($parents->getModel()->qualifyColumn('hs_event_id'));
            $sites = HsEvent::query()->whereIn('hs_events.id', $proof)->select('hs_events.site_id');
            $parents->with(['hsEvent.site' => function ($relation) use ($original, $sites, $proof): void {
                $original($relation);
                $relation->whereIn('sites.id', clone $sites);
                $this->reconsumeOriginalSource($relation->getQuery(), $proof);
            }]);
        }

        return $this->protectMaterialisation($parents);
    }

    /** whereIn compiles subqueries immediately; reconsume the SAME original guard at final body compilation. */
    private function reconsumeOriginalSource(Builder $destination, Builder $originalSource): Builder
    {
        return $this->protectMaterialisation($destination->whereRaw(new class(clone $originalSource) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly Builder $originalSource)
            {
                parent::__construct('1 = 1');
            }

            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                // SQL compilation only: no new authority, source discovery or replacement baseline.
                (clone $this->originalSource)->toSql();

                return parent::getValue($grammar);
            }
        }));
    }
}
