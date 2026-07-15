<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsTrainingRequirement;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\WorkplaceInjury;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Health & Safety KPI calculation service (NZ frameworks).
 *
 * Computes the leading + lagging metrics the command-centre dashboard binds to:
 * LTIFR, TRIFR, injury severity rate, near-miss:incident ratio, corrective-actions
 * closed-on-time %, days-since-last-lost-time-injury and training/audit compliance %.
 *
 * Frequency rates use the standard NZ/AU "per 1,000,000 hours worked" denominator.
 * The hours-worked source is {@see BillingEntry::$hours} (the only SQL-summable worked-hours
 * column, scoped by site + service_date). Rate methods return NULL below MIN_RATE_HOURS of
 * worked exposure so the dashboard renders "—" rather than a divide-by-near-zero figure that
 * would extrapolate one injury into a six-figure rate (never fabricated data).
 *
 * All methods are read-only. No mutations.
 */
class HsKpiService
{
    /** Per-million-hours-worked base multiplier for LTIFR / TRIFR / severity rate. */
    private const FREQUENCY_BASE = 1_000_000;

    /**
     * Minimum worked-hours in the basis window before a per-million-hours frequency rate is
     * reported. Below this the denominator is too small to be meaningful — a handful of logged
     * hours would extrapolate a single injury into a six-figure rate — so the rate returns NULL
     * and the dashboard shows "—" instead. (Standard frequency rates assume substantial exposure.)
     */
    private const MIN_RATE_HOURS = 1_000;

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
     * Per-site rates use the immutable billing-entry site FK. The name snapshot is
     * presentation/audit evidence only: names are mutable and not guaranteed unique.
     */
    public function totalHoursWorked(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): float
    {
        [$from, $to] = $this->rateWindow($from, $to);

        $query = BillingEntry::query()
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()]);
        $this->applySiteScope($query, $siteId);

        return (float) $query->sum('hours');
    }

    /* ------------------------------------------------------------------ */
    /*  Lagging — outcomes                                                 */
    /* ------------------------------------------------------------------ */

    /** Lost-Time Injury Frequency Rate = (lost-time injuries ÷ hours) × 1,000,000. */
    public function ltifr(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): ?float
    {
        [$from, $to] = $this->rateWindow($from, $to);
        $hours = $this->totalHoursWorked($from, $to, $siteId);
        if ($hours < self::MIN_RATE_HOURS) {
            return null;
        }

        $lostTimeQuery = WorkplaceInjury::withLostTime()
            ->whereBetween('injury_date', [$from, $to]);
        $this->applySiteScope($lostTimeQuery, $siteId);
        $lostTime = $lostTimeQuery->count();

        return round($lostTime / $hours * self::FREQUENCY_BASE, 1);
    }

    /**
     * Total Recordable Injury Frequency Rate = (recordable injuries ÷ hours) × 1,000,000.
     * Recordable = medical-treatment + lost-time + notifiable (fatalities/serious). See rule below.
     */
    public function trifr(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): ?float
    {
        [$from, $to] = $this->rateWindow($from, $to);
        $hours = $this->totalHoursWorked($from, $to, $siteId);
        if ($hours < self::MIN_RATE_HOURS) {
            return null;
        }

        $recordable = $this->recordableInjuriesQuery($from, $to, $siteId)->count();

        return round($recordable / $hours * self::FREQUENCY_BASE, 1);
    }

    /** Injury severity rate = (lost-time days ÷ hours) × 1,000,000. */
    public function injurySeverityRate(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): ?float
    {
        [$from, $to] = $this->rateWindow($from, $to);
        $hours = $this->totalHoursWorked($from, $to, $siteId);
        if ($hours < self::MIN_RATE_HOURS) {
            return null;
        }

        $injuryQuery = WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to]);
        $this->applySiteScope($injuryQuery, $siteId);
        $lostDays = (int) $injuryQuery->sum('lost_time_days');

        return round($lostDays / $hours * self::FREQUENCY_BASE, 1);
    }

    /** Whole days since the most recent lost-time injury (null if none on record). */
    public function daysSinceLostTimeInjury(int|array|null $siteId = null): ?int
    {
        $query = WorkplaceInjury::withLostTime();
        $this->applySiteScope($query, $siteId);
        $last = $query->max('injury_date');

        return $last ? (int) Carbon::parse($last)->diffInDays(now()) : null;
    }

    /** Recordable incidents (non-near-miss) reported in the window. */
    public function incidentsInPeriod(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): int
    {
        return $this->incidentQuery($from, $to, $siteId)
            ->where('type', '!=', 'near_miss')
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /*  Leading — proactive                                                */
    /* ------------------------------------------------------------------ */

    /** Near misses reported in the window (proactive-reporting signal). */
    public function nearMissesInPeriod(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): int
    {
        return $this->incidentQuery($from, $to, $siteId)
            ->where('type', 'near_miss')
            ->count();
    }

    /**
     * Near-miss : incident ratio = near misses ÷ recordable incidents (higher = healthier
     * reporting culture). Denominator uses recordable injuries over a trailing 12-month basis.
     */
    public function nearMissToIncidentRatio(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): ?float
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
    public function actionsClosedOnTimePct(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): ?float
    {
        [$from, $to] = $this->countWindow($from, $to);

        $base = HsCorrectiveAction::query()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyRelatedSiteScope($base, 'hsEvent', $siteId);

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
    public function trainingAuditCompliancePct(int|array|null $siteId = null): ?float
    {
        $requirementIds = $this->trainingRequirementQuery($siteId)
            ->pluck('hr_compliance_requirement_id')
            ->filter()
            ->values();

        if ($requirementIds->isEmpty()) {
            return null;
        }

        $base = HrStaffComplianceStatus::query()->whereIn('requirement_id', $requirementIds);
        $this->applyStaffSiteScope($base, $siteId);

        $total = (clone $base)->count();
        if ($total <= 0) {
            return null;
        }

        $nonCompliant = (clone $base)
            ->whereIn('status', ['expired', 'not_started'])
            ->count();

        return round(($total - $nonCompliant) / $total * 100, 0);
    }

    /** Currently open hazards (open + in_progress), optionally per site. */
    public function openHazards(int|array|null $siteId = null): int
    {
        $query = SiteHazard::whereIn('status', ['open', 'in_progress']);
        $this->applySiteScope($query, $siteId);

        return $query->count();
    }

    /**
     * First-aid treatments logged in the window — a leading care-activity signal (first-aid-only
     * treatment is NOT recordable and is excluded from TRIFR). Trailing 30d default; site-scoped
     * directly via first_aid_records.site_id.
     *
     * @return array{treatments:int,ambulance:int,hospital:int}
     */
    public function firstAidActivity(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): array
    {
        [$from, $to] = $this->countWindow($from, $to);
        if (! Schema::hasTable('first_aid_records')) {
            return ['treatments' => 0, 'ambulance' => 0, 'hospital' => 0];
        }

        $base = FirstAidRecord::query()
            ->whereBetween('treatment_date', [$from, $to]);
        $this->applySiteScope($base, $siteId);

        return [
            'treatments' => (clone $base)->count(),
            'ambulance' => (clone $base)->where('ambulance_called', true)->count(),
            'hospital' => (clone $base)->where('treatment_outcome', 'sent_to_hospital')->count(),
        ];
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
    public function monthlyFrequencyRates(int $months = 12, int|array|null $siteId = null): array
    {
        $end = now()->endOfMonth();
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = $end->copy()->subMonths($i)->endOfMonth();
            $windowStart = $monthEnd->copy()->subMonths(11)->startOfMonth();

            $hours = $this->totalHoursWorked($windowStart, $monthEnd, $siteId);
            $lostTimeQuery = WorkplaceInjury::withLostTime()
                ->whereBetween('injury_date', [$windowStart, $monthEnd]);
            $this->applySiteScope($lostTimeQuery, $siteId);
            $lostTime = $lostTimeQuery->count();
            $recordable = $this->recordableInjuriesQuery($windowStart, $monthEnd, $siteId)->count();

            $rows[] = [
                'month' => $monthEnd->format('Y-m'),
                'ltifr' => $hours >= self::MIN_RATE_HOURS ? round($lostTime / $hours * self::FREQUENCY_BASE, 1) : null,
                'trifr' => $hours >= self::MIN_RATE_HOURS ? round($recordable / $hours * self::FREQUENCY_BASE, 1) : null,
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
    public function leadingLagging(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): array
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
                'training_pct' => $this->trainingAuditCompliancePct($siteId),
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
    public function nearMissOperands(?CarbonInterface $from = null, ?CarbonInterface $to = null, int|array|null $siteId = null): array
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
    public function hazardBurndown(int $weeks = 6, int|array|null $siteId = null): array
    {
        $rows = [];
        $end = now()->endOfWeek();

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekEnd = $end->copy()->subWeeks($i);
            $query = SiteHazard::query()
                ->where('created_at', '<=', $weekEnd)
                ->where(function (Builder $q) use ($weekEnd) {
                    $q->whereNull('closed_at')->orWhere('closed_at', '>', $weekEnd);
                });
            $this->applySiteScope($query, $siteId);
            $open = $query->count();
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
    private function recordableInjuriesQuery(CarbonInterface $from, CarbonInterface $to, int|array|null $siteId): Builder
    {
        $query = WorkplaceInjury::query()
            ->whereBetween('injury_date', [$from, $to])
            ->where(function (Builder $q) {
                $q->where('lost_time_days', '>', 0)
                    ->orWhereIn('medical_treatment_type', self::RECORDABLE_TREATMENTS)
                    ->orWhere('worksafe_notifiable', true);
            });

        return $this->applySiteScope($query, $siteId);
    }

    /** ClientIncident in the window; site-scoped by its incident-time site snapshot. */
    private function incidentQuery(?CarbonInterface $from, ?CarbonInterface $to, int|array|null $siteId): Builder
    {
        [$from, $to] = $this->countWindow($from, $to);

        $query = ClientIncident::query()
            ->whereBetween('occurred_at', [$from, $to]);

        return $this->applySiteScope($query, $siteId);
    }

    private function applyStaffSiteScope(Builder $query, int|array|null $siteId): Builder
    {
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);

        return $query->whereHas('user.hrEmployeeProfile', function (Builder $profileQuery) use ($siteIds): void {
            $profileQuery->whereIn('primary_site_id', $siteIds);
            foreach ($siteIds as $id) {
                $profileQuery->orWhereJsonContains('secondary_site_ids', $id);
            }
        });
    }

    private function trainingRequirementQuery(int|array|null $siteId): Builder
    {
        $query = HsTrainingRequirement::query()->active();
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);
        $clientIds = Client::query()->whereIn('site_id', $siteIds)->pluck('id');

        return $query->where(function (Builder $scope) use ($siteIds, $clientIds): void {
            $scope->whereIn('scope_type', [
                HsTrainingRequirement::SCOPE_GLOBAL,
                HsTrainingRequirement::SCOPE_ROLE,
            ]);

            foreach ($siteIds as $id) {
                $scope->orWhere(function (Builder $siteScope) use ($id): void {
                    $siteScope->where('scope_type', HsTrainingRequirement::SCOPE_SITE)
                        ->whereJsonContains('scope_site_ids', $id);
                });
            }

            foreach ($clientIds as $clientId) {
                $scope->orWhere(function (Builder $clientScope) use ($clientId): void {
                    $clientScope->where('scope_type', HsTrainingRequirement::SCOPE_CLIENT)
                        ->whereJsonContains('scope_client_ids', (int) $clientId);
                });
            }
        });
    }

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

    private function applyRelatedSiteScope(
        Builder $query,
        string $relationship,
        int|array|null $siteId,
    ): Builder {
        if ($siteId === null) {
            return $query;
        }

        return $query->whereHas(
            $relationship,
            fn (Builder $related) => $this->applySiteScope($related, $siteId),
        );
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
