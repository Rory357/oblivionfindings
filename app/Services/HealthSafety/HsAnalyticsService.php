<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\ClientIncident;
use App\Models\EmergencyDrill;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsCorrectiveAction;
use App\Models\SiteHazard;
use App\Models\Site;
use App\Models\StaffTrainingRecord;
use App\Models\Timesheet;
use App\Models\WorkplaceInjury;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 * Honesty rule (audit §9): LTIFR is truthful (lost_time_days > 0 over real
 * timesheet hours). TRIFR uses a documented "recordable" heuristic because
 * workplace_injuries has no clean recordable flag. When hours = 0 for a
 * period the rate is null (UI shows "needs hours data"), never a fake number.
 */
class HsAnalyticsService
{
    /** Recordable-injury heuristic for TRIFR (audit §9). */
    private const RECORDABLE_TREATMENT = ['medical_centre', 'hospital', 'ambulance'];

    /** Timesheet statuses that count as confirmed worked hours. */
    private const HOURS_STATUSES = ['submitted', 'approved'];

    /**
     * @param  int|null  $siteId  null = all sites
     * @param  string  $lens  governance|manager|frontline
     * @return array<string,mixed>
     */
    public function build(?int $siteId, CarbonInterface $from, CarbonInterface $to, string $lens): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        // Trends always span a rolling 12-month base (or the window if longer)
        // so the charts stay readable even for a 30-day KPI window.
        $trendFrom = $from->copy()->startOfMonth()->min($to->copy()->subMonths(11)->startOfMonth());
        $months = $this->monthsBetween($trendFrom, $to);

        $hours = $this->hoursByMonth($siteId, $trendFrom, $to, $months);
        $trends = $this->buildTrends($siteId, $trendFrom, $to, $months, $hours['by_month']);

        $windowHours = $this->windowHours($siteId, $from, $to);
        $heroStats = $this->heroStats($siteId, $from, $to, $windowHours, $trends);
        $scorecard = $this->scorecard($siteId, $from, $to, $heroStats, $trends);

        return [
            'incident_data' => $this->incidentsByType($siteId, $from, $to),
            'severity_data' => $this->incidentsBySeverity($siteId, $from, $to),
            'root_cause_data' => $this->rootCausePareto($siteId, $from, $to),
            'injury_data' => [
                'by_type' => $this->injuriesByType($siteId, $from, $to),
                'by_body_part' => $this->injuriesByBodyPart($siteId, $from, $to),
            ],
            'hazard_data' => $this->hazardsByRisk($siteId),
            'site_comparison' => $this->siteComparison($from, $to),
            'trends' => $trends,
            'hero_stats' => $heroStats,
            'scorecard' => $scorecard,
            'period_summary' => $this->periodSummary($siteId, $from, $to),
            'worksafe_notifiable' => $this->worksafeTotals($from, $to),
            'hours_meta' => [
                'source' => $hours['source'],
                'total_hours' => round($windowHours, 0),
            ],
            'role_note' => $this->roleNote($lens),
        ];
    }

    // ── Monthly trend series ────────────────────────────────────────────

    /**
     * @param  array<int,string>  $months
     * @param  array<string,float>  $hoursByMonth
     * @return array<int,array<string,mixed>>
     */
    private function buildTrends(?int $siteId, CarbonInterface $from, CarbonInterface $to, array $months, array $hoursByMonth): array
    {
        $lti = $this->injuryCountsByMonth($siteId, $from, $to, lostTimeOnly: true);
        $recordable = $this->injuryCountsByMonth($siteId, $from, $to, recordableOnly: true);

        $incidents = $this->incidentCountsByMonth($siteId, $from, $to);
        $hazOpened = $this->hazardOpenedByMonth($siteId, $from, $to);
        $hazClosed = $this->hazardClosedByMonth($siteId, $from, $to);
        $runningOpen = $this->hazardRunningOpen($siteId, $from, $months, $hazOpened, $hazClosed);

        $ca = $this->correctiveActionByMonth($from, $to);
        $compliance = $this->complianceByMonth($months);
        $engagement = $this->engagementByMonth($from, $to);
        $consultation = $this->consultationByMonth($from, $to);
        $worksafe = $this->worksafeByMonth($from, $to);

        return collect($months)->map(function (string $m) use (
            $hoursByMonth, $lti, $recordable, $incidents, $hazOpened, $hazClosed,
            $runningOpen, $ca, $compliance, $engagement, $consultation, $worksafe
        ) {
            $hrs = $hoursByMonth[$m] ?? 0.0;
            $ltiN = $lti[$m] ?? 0;
            $recN = $recordable[$m] ?? 0;
            $inc = $incidents[$m] ?? ['total' => 0, 'near_miss' => 0];
            $nm = $inc['near_miss'];
            $other = max($inc['total'] - $nm, 0);

            return [
                'month' => $m,
                'label' => Carbon::parse($m.'-01')->format('M y'),
                'ltifr' => $hrs > 0 ? round($ltiN * 1_000_000 / $hrs, 1) : null,
                'trifr' => $hrs > 0 ? round($recN * 1_000_000 / $hrs, 1) : null,
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
                'worksafe_notified' => $worksafe[$m]['notified'] ?? 0,
                'worksafe_awaiting' => $worksafe[$m]['awaiting'] ?? 0,
            ];
        })->all();
    }

    // ── Hours worked (LTIFR/TRIFR denominator) ──────────────────────────

    /**
     * @param  array<int,string>  $months
     * @return array{by_month: array<string,float>, source: string}
     */
    private function hoursByMonth(?int $siteId, CarbonInterface $from, CarbonInterface $to, array $months): array
    {
        $ts = Timesheet::query()
            ->whereIn('status', self::HOURS_STATUSES)
            ->whereBetween('work_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw("DATE_FORMAT(work_date, '%Y-%m') as m, SUM(GREATEST(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) - COALESCE(break_minutes, 0), 0)) as mins")
            ->groupBy('m')
            ->pluck('mins', 'm');

        if ($ts->sum() > 0) {
            return ['by_month' => $ts->map(fn ($mins) => (float) $mins / 60)->all(), 'source' => 'timesheets'];
        }

        // Fallback: rostered shift hours (flagged honestly in the payload).
        $shifts = DB::table('shifts')
            ->whereBetween('starts_at', [$from, $to])
            ->whereNull('deleted_at')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw("DATE_FORMAT(starts_at, '%Y-%m') as m, SUM(GREATEST(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) - COALESCE(expected_break_minutes, 0), 0)) as mins")
            ->groupBy('m')
            ->pluck('mins', 'm');

        return [
            'by_month' => $shifts->map(fn ($mins) => (float) $mins / 60)->all(),
            'source' => $shifts->sum() > 0 ? 'rostered_fallback' : 'none',
        ];
    }

    private function windowHours(?int $siteId, CarbonInterface $from, CarbonInterface $to): float
    {
        $mins = Timesheet::query()
            ->whereIn('status', self::HOURS_STATUSES)
            ->whereBetween('work_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('SUM(GREATEST(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) - COALESCE(break_minutes, 0), 0)) as mins')
            ->value('mins');

        if ((float) $mins > 0) {
            return (float) $mins / 60;
        }

        $shiftMins = DB::table('shifts')
            ->whereBetween('starts_at', [$from, $to])
            ->whereNull('deleted_at')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('SUM(GREATEST(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) - COALESCE(expected_break_minutes, 0), 0)) as mins')
            ->value('mins');

        return (float) ($shiftMins ?? 0) / 60;
    }

    // ── Injuries ────────────────────────────────────────────────────────

    /** @return array<string,int> month => count */
    private function injuryCountsByMonth(?int $siteId, CarbonInterface $from, CarbonInterface $to, bool $lostTimeOnly = false, bool $recordableOnly = false): array
    {
        return WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($lostTimeOnly, fn ($q) => $q->where('lost_time_days', '>', 0))
            ->when($recordableOnly, fn ($q) => $this->applyRecordable($q))
            ->selectRaw("DATE_FORMAT(injury_date, '%Y-%m') as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Recordable = lost-time OR notifiable OR medical-treatment-beyond-first-aid (audit §9). */
    private function applyRecordable($query)
    {
        return $query->where(function ($q) {
            $q->where('lost_time_days', '>', 0)
                ->orWhere('worksafe_notifiable', true)
                ->orWhereIn('medical_treatment_type', self::RECORDABLE_TREATMENT);
        });
    }

    /** @return array<int,array{type:string,count:int}> */
    private function injuriesByType(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('injury_type as type, COUNT(*) as count')
            ->groupBy('injury_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'count' => (int) $r->count])
            ->all();
    }

    /** @return array<int,array{body_part:string,count:int}> */
    private function injuriesByBodyPart(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->whereNotNull('body_part_affected')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('body_part_affected as body_part, COUNT(*) as count')
            ->groupBy('body_part_affected')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['body_part' => (string) $r->body_part, 'count' => (int) $r->count])
            ->all();
    }

    // ── Incidents ───────────────────────────────────────────────────────

    /**
     * client_incidents has NO site_id — scope through client.site_id.
     *
     * @return array<string,array{total:int,near_miss:int}>
     */
    private function incidentCountsByMonth(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
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
    private function incidentsByType(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'count' => (int) $r->count])
            ->all();
    }

    /** @return array<int,array{severity:string,count:int}> */
    private function incidentsBySeverity(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['severity' => (string) ($r->severity ?: 'unspecified'), 'count' => (int) $r->count])
            ->all();
    }

    /** Ordered desc with running cumulative % for the Pareto line. @return array<int,array<string,mixed>> */
    private function rootCausePareto(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('root_cause_category')
            ->where('root_cause_category', '!=', '')
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
            ->selectRaw('root_cause_category as cause, COUNT(*) as count')
            ->groupBy('root_cause_category')
            ->orderByDesc('count')
            ->get();

        $total = (int) $rows->sum('count') ?: 1;
        $running = 0;

        return $rows->map(function ($r) use ($total, &$running) {
            $running += (int) $r->count;

            return [
                'cause' => (string) $r->cause,
                'count' => (int) $r->count,
                'pct' => round((int) $r->count / $total * 100, 1),
                'cumulative_pct' => round($running / $total * 100, 1),
            ];
        })->all();
    }

    // ── Hazards ─────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function hazardOpenedByMonth(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return SiteHazard::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @return array<string,int> */
    private function hazardClosedByMonth(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        return SiteHazard::query()
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
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
    private function hazardRunningOpen(?int $siteId, CarbonInterface $from, array $months, array $opened, array $closed): array
    {
        $baseline = SiteHazard::query()
            ->where('created_at', '<', $from)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
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
    private function hazardsByRisk(?int $siteId): array
    {
        return SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('risk_rating, COUNT(*) as count')
            ->groupBy('risk_rating')
            ->get()
            ->map(fn ($r) => ['risk_rating' => (string) $r->risk_rating, 'count' => (int) $r->count])
            ->all();
    }

    // ── Corrective actions (org-wide governance metric) ─────────────────

    /** @return array<string,array{avg_days:float|null,pct_on_time:float|null}> */
    private function correctiveActionByMonth(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = HsCorrectiveAction::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
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
    private function complianceByMonth(array $months): array
    {
        $records = StaffTrainingRecord::query()
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

    // ── Worker participation (org-wide HSWA engagement duty) ─────────────

    /** Engagement % = committee meetings held ÷ scheduled, per month. @return array<string,int|null> */
    private function engagementByMonth(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = HsCommitteeMeeting::query()
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(scheduled_at, '%Y-%m') as m, SUM(status = 'completed') as done, COUNT(*) as total")
            ->groupBy('m')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->m] = (int) $r->total > 0 ? (int) round((int) $r->done / (int) $r->total * 100) : null;
        }

        return $out;
    }

    /** Consultation completion % = actioned/closed ÷ total, per month. @return array<string,int|null> */
    private function consultationByMonth(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = HsConsultation::query()
            ->whereBetween('consultation_date', [$from, $to])
            ->selectRaw("DATE_FORMAT(consultation_date, '%Y-%m') as m, SUM(status IN ('actioned', 'closed')) as done, COUNT(*) as total")
            ->groupBy('m')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->m] = (int) $r->total > 0 ? (int) round((int) $r->done / (int) $r->total * 100) : null;
        }

        return $out;
    }

    // ── WorkSafe notifiable (HSWA s.56) ─────────────────────────────────

    /** @return array<string,array{notified:int,awaiting:int}> */
    private function worksafeByMonth(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = NotifiableIncident::query()
            ->where('notification_authority', 'worksafe')
            ->whereBetween('occurred_at', [$from, $to])
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
    private function worksafeTotals(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'notified' => (int) NotifiableIncident::query()
                ->where('notification_authority', 'worksafe')
                ->where('status', '!=', 'pending')
                ->whereBetween('occurred_at', [$from, $to])
                ->count(),
            // Awaiting is a live state — not window-bound.
            'awaiting' => (int) NotifiableIncident::query()
                ->where('notification_authority', 'worksafe')
                ->where('status', 'pending')
                ->count(),
        ];
    }

    // ── Site league + heatmap (the site-scoping bug fix) ────────────────

    /** @return array<int,array<string,mixed>> */
    private function siteComparison(CarbonInterface $from, CarbonInterface $to): array
    {
        // Incidents per site — the FIX: client_incidents has no site_id, so
        // join clients and group by clients.site_id (one query, not N).
        $incidentsBySite = ClientIncident::query()
            ->join('clients', 'clients.id', '=', 'client_incidents.client_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('clients.site_id')
            ->selectRaw('clients.site_id as site_id, COUNT(*) as c')
            ->pluck('c', 'site_id');

        $openHazardsBySite = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) as c')
            ->pluck('c', 'site_id');

        $lostDaysBySite = WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(lost_time_days) as d')
            ->pluck('d', 'site_id');

        $ltiBySite = WorkplaceInjury::query()
            ->where('lost_time_days', '>', 0)
            ->whereBetween('injury_date', [$from, $to])
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) as c')
            ->pluck('c', 'site_id');

        $recBySite = $this->applyRecordable(WorkplaceInjury::query())
            ->whereBetween('injury_date', [$from, $to])
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) as c')
            ->pluck('c', 'site_id');

        $hoursBySite = Timesheet::query()
            ->whereIn('status', self::HOURS_STATUSES)
            ->whereBetween('work_date', [$from, $to])
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(GREATEST(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) - COALESCE(break_minutes, 0), 0)) as mins')
            ->pluck('mins', 'site_id');

        $drillBySite = $this->drillStatusBySite();

        return Site::query()->orderBy('name')->get(['id', 'name'])->map(function ($site) use (
            $incidentsBySite, $openHazardsBySite, $lostDaysBySite, $ltiBySite, $recBySite, $hoursBySite, $drillBySite
        ) {
            $hours = (float) ($hoursBySite[$site->id] ?? 0) / 60;
            $incidents = (int) ($incidentsBySite[$site->id] ?? 0);
            $openHazards = (int) ($openHazardsBySite[$site->id] ?? 0);
            $drillStatus = $drillBySite[$site->id] ?? 'overdue';

            $score = 100;
            $score -= min($incidents * 5, 30);
            $score -= min($openHazards * 10, 30);
            $score -= $drillStatus === 'overdue' ? 20 : ($drillStatus === 'due_soon' ? 10 : 0);

            return [
                'id' => $site->id,
                'name' => $site->name,
                'total_incidents' => $incidents,
                'open_hazards' => $openHazards,
                'lost_time_days' => (int) ($lostDaysBySite[$site->id] ?? 0),
                'ltifr' => $hours > 0 ? round((int) ($ltiBySite[$site->id] ?? 0) * 1_000_000 / $hours, 1) : null,
                'trifr' => $hours > 0 ? round((int) ($recBySite[$site->id] ?? 0) * 1_000_000 / $hours, 1) : null,
                'drill_status' => $drillStatus,
                'compliance_score' => max(0, $score),
            ];
        })->all();
    }

    /** @return array<int,string> site_id => compliant|due_soon|overdue */
    private function drillStatusBySite(): array
    {
        $sixMonthsAgo = Carbon::now()->subMonths(6);
        $lastDrills = EmergencyDrill::query()
            ->whereNotNull('completed_at')
            ->groupBy('site_id')
            ->selectRaw('site_id, MAX(completed_at) as last')
            ->pluck('last', 'site_id');

        return $lastDrills->map(function ($last) use ($sixMonthsAgo) {
            $d = Carbon::parse($last);
            if ($d->gte($sixMonthsAgo)) {
                return 'compliant';
            }

            return $d->gte($sixMonthsAgo->copy()->subMonth()) ? 'due_soon' : 'overdue';
        })->all();
    }

    // ── Hero stats + scorecard ──────────────────────────────────────────

    /**
     * @param  array<int,array<string,mixed>>  $trends
     * @return array<string,mixed>
     */
    private function heroStats(?int $siteId, CarbonInterface $from, CarbonInterface $to, float $windowHours, array $trends): array
    {
        $lti = (int) WorkplaceInjury::query()->where('lost_time_days', '>', 0)
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))->count();
        $rec = (int) $this->applyRecordable(WorkplaceInjury::query())
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))->count();

        $nm = (int) ClientIncident::query()->where('type', 'near_miss')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))->count();
        $other = (int) ClientIncident::query()->where('type', '!=', 'near_miss')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))->count();

        $latestCompliance = collect($trends)->reverse()->firstWhere('compliance_pct', '!==', null)['compliance_pct'] ?? null;

        return [
            'ltifr' => array_merge(
                ['value' => $windowHours > 0 ? round($lti * 1_000_000 / $windowHours, 1) : null],
                $this->deltaFor($trends, 'ltifr', lowerIsBetter: true)
            ),
            'trifr' => array_merge(
                ['value' => $windowHours > 0 ? round($rec * 1_000_000 / $windowHours, 1) : null],
                $this->deltaFor($trends, 'trifr', lowerIsBetter: true)
            ),
            'near_miss_ratio' => array_merge(
                ['value' => $other > 0 ? round($nm / $other, 1) : (float) $nm],
                $this->deltaFor($trends, 'near_miss_ratio', lowerIsBetter: false)
            ),
            'compliance_pct' => array_merge(
                ['value' => $latestCompliance],
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
     * Leading-vs-lagging scorecard (board-ready).
     *
     * @param  array<string,mixed>  $heroStats
     * @param  array<int,array<string,mixed>>  $trends
     * @return array{leading:array<int,array<string,mixed>>,lagging:array<int,array<string,mixed>>}
     */
    private function scorecard(?int $siteId, CarbonInterface $from, CarbonInterface $to, array $heroStats, array $trends): array
    {
        $latest = collect($trends)->last() ?? [];

        $openHazards = (int) SiteHazard::query()->whereIn('status', ['open', 'in_progress'])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))->count();
        $incidents = (int) ClientIncident::query()->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))->count();
        $lostDays = (int) WorkplaceInjury::query()->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))->sum('lost_time_days');

        $lastLti = WorkplaceInjury::query()->where('lost_time_days', '>', 0)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))->max('injury_date');
        $daysSinceLti = $lastLti ? (int) Carbon::parse($lastLti)->diffInDays(Carbon::now()) : null;

        $leading = [
            ['key' => 'near_miss_ratio', 'label' => 'Near-miss reporting ratio', 'value' => $heroStats['near_miss_ratio']['value'], 'suffix' => ':1', 'delta' => $heroStats['near_miss_ratio']['delta'], 'dir' => $heroStats['near_miss_ratio']['dir']],
            ['key' => 'ca_pct_on_time', 'label' => 'Corrective actions on time', 'value' => $latest['ca_pct_on_time'] ?? null, 'suffix' => '%', 'delta' => $this->deltaFor($trends, 'ca_pct_on_time', false)['delta'], 'dir' => $this->deltaFor($trends, 'ca_pct_on_time', false)['dir']],
            ['key' => 'compliance_pct', 'label' => 'Training & audit compliance', 'value' => $heroStats['compliance_pct']['value'], 'suffix' => '%', 'delta' => $heroStats['compliance_pct']['delta'], 'dir' => $heroStats['compliance_pct']['dir']],
            ['key' => 'worker_engagement', 'label' => 'Worker participation', 'value' => $latest['worker_engagement'] ?? null, 'suffix' => '%', 'delta' => $this->deltaFor($trends, 'worker_engagement', false)['delta'], 'dir' => $this->deltaFor($trends, 'worker_engagement', false)['dir']],
            ['key' => 'hazards_open', 'label' => 'Open hazards', 'value' => $openHazards, 'suffix' => '', 'delta' => $this->deltaFor($trends, 'hazards_open', true)['delta'], 'dir' => $this->deltaFor($trends, 'hazards_open', true)['dir']],
            ['key' => 'worker_consultation', 'label' => 'Consultation completion', 'value' => $latest['worker_consultation'] ?? null, 'suffix' => '%', 'delta' => $this->deltaFor($trends, 'worker_consultation', false)['delta'], 'dir' => $this->deltaFor($trends, 'worker_consultation', false)['dir']],
        ];

        $lagging = [
            ['key' => 'ltifr', 'label' => 'LTIFR', 'value' => $heroStats['ltifr']['value'], 'suffix' => '', 'delta' => $heroStats['ltifr']['delta'], 'dir' => $heroStats['ltifr']['dir']],
            ['key' => 'trifr', 'label' => 'TRIFR', 'value' => $heroStats['trifr']['value'], 'suffix' => '', 'delta' => $heroStats['trifr']['delta'], 'dir' => $heroStats['trifr']['dir']],
            ['key' => 'incidents', 'label' => 'Incidents', 'value' => $incidents, 'suffix' => '', 'delta' => $this->deltaFor($trends, 'incidents', true)['delta'], 'dir' => $this->deltaFor($trends, 'incidents', true)['dir']],
            ['key' => 'lost_time_days', 'label' => 'Lost-time days', 'value' => $lostDays, 'suffix' => '', 'delta' => null, 'dir' => 'flat'],
            ['key' => 'days_since_lti', 'label' => 'Days since last LTI', 'value' => $daysSinceLti, 'suffix' => 'd', 'delta' => null, 'dir' => 'flat'],
            ['key' => 'worksafe_awaiting', 'label' => 'WorkSafe awaiting', 'value' => $this->worksafeTotals($from, $to)['awaiting'], 'suffix' => '', 'delta' => null, 'dir' => 'flat'],
        ];

        return ['leading' => $leading, 'lagging' => $lagging];
    }

    // ── Period summary + role note ──────────────────────────────────────

    /** @return array<string,mixed> */
    private function periodSummary(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $incidents = (int) ClientIncident::query()->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))->count();
        $nearMisses = (int) ClientIncident::query()->where('type', 'near_miss')->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))->count();
        $openHazards = (int) SiteHazard::query()->whereIn('status', ['open', 'in_progress'])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))->count();

        $caTotal = (int) HsCorrectiveAction::query()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])->count();
        $caOnTime = (int) HsCorrectiveAction::query()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])
            ->whereNotNull('due_date')->whereColumn('completed_at', '<=', 'due_date')->count();

        $totalSites = (int) Site::query()->count();
        $drillsComplete = count(array_filter($this->drillStatusBySite(), fn ($s) => $s === 'compliant'));

        return [
            'incidents' => $incidents,
            'near_misses' => $nearMisses,
            'worksafe_awaiting' => $this->worksafeTotals($from, $to)['awaiting'],
            'open_hazards' => $openHazards,
            'actions_on_time_pct' => $caTotal > 0 ? (int) round($caOnTime / $caTotal * 100) : null,
            'drills_complete' => $drillsComplete,
            'drills_total' => $totalSites,
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
     * Rows for a CSV export of the active view. Read-only register records,
     * site- and range-scoped to match what the page shows.
     *
     * @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
     */
    public function exportRows(string $view, ?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $siteNames = Site::query()->pluck('name', 'id');

        return match ($view) {
            'injuries' => $this->exportInjuries($siteId, $from, $to, $siteNames),
            'hazards' => $this->exportHazards($siteId, $from, $to, $siteNames),
            'sites' => $this->exportSites($from, $to),
            'root_cause' => [
                'name' => 'root_cause',
                'headers' => ['Cause', 'Count', '% of total', 'Cumulative %'],
                'rows' => array_map(
                    fn ($r) => [$r['cause'], $r['count'], $r['pct'], $r['cumulative_pct']],
                    $this->rootCausePareto($siteId, $from, $to)
                ),
            ],
            default => $this->exportIncidents($siteId, $from, $to, $siteNames),
        };
    }

    /** @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>} */
    private function exportIncidents(?int $siteId, CarbonInterface $from, CarbonInterface $to, Collection $siteNames): array
    {
        $rows = ClientIncident::query()
            ->with('client:id,first_name,last_name,site_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
            ->orderByDesc('occurred_at')
            ->get();

        return [
            'name' => 'incidents',
            'headers' => ['ID', 'Occurred', 'Type', 'Severity', 'Status', 'Site', 'Client', 'Root cause'],
            'rows' => $rows->map(fn ($i) => [
                $i->id,
                optional($i->occurred_at)->toDateString(),
                $i->type,
                $i->severity,
                $i->status,
                $siteNames[$i->client?->site_id] ?? '—',
                trim(($i->client?->first_name ?? '').' '.($i->client?->last_name ?? '')) ?: '—',
                $i->root_cause_category ?? '—',
            ])->all(),
        ];
    }

    /** @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>} */
    private function exportInjuries(?int $siteId, CarbonInterface $from, CarbonInterface $to, Collection $siteNames): array
    {
        $rows = WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('injury_date')
            ->get();

        return [
            'name' => 'injuries',
            'headers' => ['ID', 'Date', 'Type', 'Body part', 'Severity', 'Lost-time days', 'Site', 'WorkSafe notifiable', 'ACC claim'],
            'rows' => $rows->map(fn ($i) => [
                $i->id,
                optional($i->injury_date)->toDateString(),
                $i->injury_type,
                $i->body_part_affected ?? '—',
                $i->severity,
                (int) $i->lost_time_days,
                $siteNames[$i->site_id] ?? '—',
                $i->worksafe_notifiable ? 'Yes' : 'No',
                $i->acc_claim_lodged ? 'Yes' : 'No',
            ])->all(),
        ];
    }

    /** @return array{name:string,headers:array<int,string>,rows:array<int,array<int,mixed>>} */
    private function exportHazards(?int $siteId, CarbonInterface $from, CarbonInterface $to, Collection $siteNames): array
    {
        $rows = SiteHazard::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
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
    private function exportSites(CarbonInterface $from, CarbonInterface $to): array
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
            ], $this->siteComparison($from, $to)),
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────

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
}
