<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\BillingEntry;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsTrainingRequirement;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\WorkplaceInjury;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Health & Safety KPI calculation service (NZ frameworks).
 *
 * Computes the leading + lagging metrics the command-centre dashboard binds to:
 * LTIFR, TRIFR, injury severity rate, near-miss:incident ratio, corrective-actions
 * closed-on-time %, days-since-last-lost-time-injury and training/audit compliance %.
 *
 * Frequency rates use the standard NZ/AU "per 1,000,000 hours worked" denominator.
 * The hours-worked source is {@see BillingEntry::$hours} (the only SQL-summable worked-hours
 * column, scoped by site + service_date). Rate methods return NULL when hours = 0 so the
 * dashboard renders "—" rather than a divide-by-zero figure (never fabricated data).
 *
 * All methods are read-only. No mutations.
 */
class HsKpiService
{
    /** Per-million-hours-worked base multiplier for LTIFR / TRIFR / severity rate. */
    private const FREQUENCY_BASE = 1_000_000;

    /**
     * medical_treatment_type values that make a WorkplaceInjury "recordable" (treatment
     * beyond first aid). Enum: none|first_aid|medical_centre|hospital|ambulance.
     */
    private const RECORDABLE_TREATMENTS = ['medical_centre', 'hospital', 'ambulance'];

    /* ------------------------------------------------------------------ */
    /*  Denominator                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Total hours worked in the window (the LTIFR/TRIFR denominator), optionally per site.
     * Defaults to a trailing 12-month window (standard annualised frequency-rate basis).
     *
     * NOTE: `billing_entries` has no `site_id` column — per-site billing is keyed on
     * `site_name_snapshot` (the point-in-time site name), matching how `ReportingService`
     * groups worked hours. We resolve the site's current name to that snapshot column.
     */
    public function totalHoursWorked(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): float
    {
        [$from, $to] = $this->rateWindow($from, $to);

        return (float) BillingEntry::query()
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->when($siteId, function (Builder $q) use ($siteId) {
                $q->where('site_name_snapshot', Site::whereKey($siteId)->value('name'));
            })
            ->sum('hours');
    }

    /* ------------------------------------------------------------------ */
    /*  Lagging — outcomes                                                 */
    /* ------------------------------------------------------------------ */

    /** Lost-Time Injury Frequency Rate = (lost-time injuries ÷ hours) × 1,000,000. */
    public function ltifr(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): ?float
    {
        [$from, $to] = $this->rateWindow($from, $to);
        $hours = $this->totalHoursWorked($from, $to, $siteId);
        if ($hours <= 0) {
            return null;
        }

        $lostTime = WorkplaceInjury::withLostTime()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->count();

        return round($lostTime / $hours * self::FREQUENCY_BASE, 1);
    }

    /**
     * Total Recordable Injury Frequency Rate = (recordable injuries ÷ hours) × 1,000,000.
     * Recordable = medical-treatment + lost-time + notifiable (fatalities/serious). See rule below.
     */
    public function trifr(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): ?float
    {
        [$from, $to] = $this->rateWindow($from, $to);
        $hours = $this->totalHoursWorked($from, $to, $siteId);
        if ($hours <= 0) {
            return null;
        }

        $recordable = $this->recordableInjuriesQuery($from, $to, $siteId)->count();

        return round($recordable / $hours * self::FREQUENCY_BASE, 1);
    }

    /** Injury severity rate = (lost-time days ÷ hours) × 1,000,000. */
    public function injurySeverityRate(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): ?float
    {
        [$from, $to] = $this->rateWindow($from, $to);
        $hours = $this->totalHoursWorked($from, $to, $siteId);
        if ($hours <= 0) {
            return null;
        }

        $lostDays = (int) WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->sum('lost_time_days');

        return round($lostDays / $hours * self::FREQUENCY_BASE, 1);
    }

    /** Whole days since the most recent lost-time injury (null if none on record). */
    public function daysSinceLostTimeInjury(?int $siteId = null): ?int
    {
        $last = WorkplaceInjury::withLostTime()
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->max('injury_date');

        return $last ? (int) Carbon::parse($last)->diffInDays(now()) : null;
    }

    /** Recordable incidents (non-near-miss) reported in the window. */
    public function incidentsInPeriod(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): int
    {
        return $this->incidentQuery($from, $to, $siteId)
            ->where('type', '!=', 'near_miss')
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /*  Leading — proactive                                                */
    /* ------------------------------------------------------------------ */

    /** Near misses reported in the window (proactive-reporting signal). */
    public function nearMissesInPeriod(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): int
    {
        return $this->incidentQuery($from, $to, $siteId)
            ->where('type', 'near_miss')
            ->count();
    }

    /**
     * Near-miss : incident ratio = near misses ÷ recordable incidents (higher = healthier
     * reporting culture). Denominator uses recordable injuries over a trailing 12-month basis.
     */
    public function nearMissToIncidentRatio(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): ?float
    {
        [$rateFrom, $rateTo] = $this->rateWindow(null, null);
        $recordable = $this->recordableInjuriesQuery($rateFrom, $rateTo, $siteId)->count();
        if ($recordable <= 0) {
            return null;
        }

        $nearMisses = $this->nearMissesInPeriod($rateFrom, $rateTo, $siteId);

        return round($nearMisses / $recordable, 1);
    }

    /**
     * Corrective actions closed on time % = actions completed on/before due date ÷ actions
     * due in the window × 100. Optionally scoped to a site via the parent HsEvent.
     */
    public function actionsClosedOnTimePct(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): ?float
    {
        [$from, $to] = $this->countWindow($from, $to);

        $base = HsCorrectiveAction::query()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->when($siteId, fn (Builder $q) => $q->whereHas('hsEvent', fn (Builder $e) => $e->where('site_id', $siteId)));

        $due = (clone $base)->count();
        if ($due <= 0) {
            return null;
        }

        $onTime = (clone $base)
            ->whereNotNull('completed_at')
            ->whereRaw('DATE(completed_at) <= due_date')
            ->count();

        return round($onTime / $due * 100, 0);
    }

    /**
     * Training & audit compliance % = compliant staff-requirement pairs ÷ total × 100,
     * over the H&S-linked HR compliance requirements. (No separate "audit" model exists yet,
     * so this is training compliance surfaced as the single design figure — see plan §10.)
     */
    public function trainingAuditCompliancePct(): ?float
    {
        $requirementIds = HsTrainingRequirement::active()
            ->pluck('hr_compliance_requirement_id')
            ->filter()
            ->values();

        if ($requirementIds->isEmpty()) {
            return null;
        }

        $total = HrStaffComplianceStatus::whereIn('requirement_id', $requirementIds)->count();
        if ($total <= 0) {
            return null;
        }

        $nonCompliant = HrStaffComplianceStatus::whereIn('requirement_id', $requirementIds)
            ->whereIn('status', ['expired', 'not_started'])
            ->count();

        return round(($total - $nonCompliant) / $total * 100, 0);
    }

    /** Currently open hazards (open + in_progress), optionally per site. */
    public function openHazards(?int $siteId = null): int
    {
        return SiteHazard::whereIn('status', ['open', 'in_progress'])
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /*  Series + bundle                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Trailing-12-month rolling LTIFR + TRIFR for each of the last $months months — the
     * two lines drawn over the incident-trend chart. Rolling windows keep the lines smooth
     * (single-month rates would swing wildly on a small denominator).
     *
     * @return array<int, array{month: string, ltifr: float|null, trifr: float|null}>
     */
    public function monthlyFrequencyRates(int $months = 12, ?int $siteId = null): array
    {
        $end = now()->endOfMonth();
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = $end->copy()->subMonths($i)->endOfMonth();
            $windowStart = $monthEnd->copy()->subMonths(11)->startOfMonth();

            $hours = $this->totalHoursWorked($windowStart, $monthEnd, $siteId);
            $lostTime = WorkplaceInjury::withLostTime()
                ->whereBetween('injury_date', [$windowStart, $monthEnd])
                ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
                ->count();
            $recordable = $this->recordableInjuriesQuery($windowStart, $monthEnd, $siteId)->count();

            $rows[] = [
                'month' => $monthEnd->format('Y-m'),
                'ltifr' => $hours > 0 ? round($lostTime / $hours * self::FREQUENCY_BASE, 1) : null,
                'trifr' => $hours > 0 ? round($recordable / $hours * self::FREQUENCY_BASE, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * The hero's leading-vs-lagging stat clusters in one call. Period-bound counts use the
     * picked window; frequency rates annualise over a trailing 12 months for a stable denominator.
     *
     * @return array{lagging: array<string, mixed>, leading: array<string, mixed>}
     */
    public function leadingLagging(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): array
    {
        return [
            'lagging' => [
                'incidents' => $this->incidentsInPeriod($from, $to, $siteId),
                'ltifr' => $this->ltifr(null, null, $siteId),
                'trifr' => $this->trifr(null, null, $siteId),
                'injury_severity_rate' => $this->injurySeverityRate(null, null, $siteId),
                'days_since_lti' => $this->daysSinceLostTimeInjury($siteId),
            ],
            'leading' => [
                'near_miss_ratio' => $this->nearMissToIncidentRatio($from, $to, $siteId),
                'actions_on_time_pct' => $this->actionsClosedOnTimePct($from, $to, $siteId),
                'training_pct' => $this->trainingAuditCompliancePct(),
                'open_hazards' => $this->openHazards($siteId),
            ],
        ];
    }

    /**
     * Operands behind the near-miss : incident ratio donut, over the trailing-12-month
     * basis: near misses reported and recordable incidents. The donut arc fills to
     * `near_misses / (near_misses + recordable)` (proactive-reporting share).
     *
     * @return array{near_misses: int, recordable: int}
     */
    public function nearMissOperands(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $siteId = null): array
    {
        [$from, $to] = $this->rateWindow($from, $to);

        return [
            'near_misses' => $this->nearMissesInPeriod($from, $to, $siteId),
            'recordable' => $this->recordableInjuriesQuery($from, $to, $siteId)->count(),
        ];
    }

    /**
     * Open-hazard burn-down series — the count of hazards still open at the end of each of
     * the last $weeks weeks (created on/before the week-end and not yet closed by then).
     *
     * @return array<int, array{week: string, open: int}>
     */
    public function hazardBurndown(int $weeks = 6, ?int $siteId = null): array
    {
        $rows = [];
        $end = now()->endOfWeek();

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekEnd = $end->copy()->subWeeks($i);
            $open = SiteHazard::query()
                ->where('created_at', '<=', $weekEnd)
                ->where(function (Builder $q) use ($weekEnd) {
                    $q->whereNull('closed_at')->orWhere('closed_at', '>', $weekEnd);
                })
                ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
                ->count();
            $rows[] = ['week' => $weekEnd->toDateString(), 'open' => $open];
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * WorkplaceInjury recordable rule (NZ/AU TRIFR): lost-time OR medical-treatment
     * (medical_centre/hospital/ambulance) OR notifiable. First-aid-only is NOT recordable.
     */
    private function recordableInjuriesQuery(CarbonInterface $from, CarbonInterface $to, ?int $siteId): Builder
    {
        return WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->where(function (Builder $q) {
                $q->where('lost_time_days', '>', 0)
                    ->orWhereIn('medical_treatment_type', self::RECORDABLE_TREATMENTS)
                    ->orWhere('worksafe_notifiable', true);
            });
    }

    /** ClientIncident in the window; site-scoped via the linked shift's site. */
    private function incidentQuery(?CarbonInterface $from, ?CarbonInterface $to, ?int $siteId): Builder
    {
        [$from, $to] = $this->countWindow($from, $to);

        return ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn (Builder $q) => $q->whereHas('shift', fn (Builder $s) => $s->where('site_id', $siteId)));
    }

    /** Counts default to a trailing 30-day window. */
    private function countWindow(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $resolvedTo = $to ? Carbon::parse($to) : now();
        $resolvedFrom = $from ? Carbon::parse($from) : $resolvedTo->copy()->subDays(30);

        return [$resolvedFrom, $resolvedTo];
    }

    /** Rates default to a trailing 12-month window (annualised frequency-rate basis). */
    private function rateWindow(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $resolvedTo = $to ? Carbon::parse($to) : now();
        $resolvedFrom = $from ? Carbon::parse($from) : now()->subMonths(12)->startOfMonth();

        return [$resolvedFrom, $resolvedTo];
    }
}
