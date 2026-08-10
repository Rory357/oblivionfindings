<?php

namespace App\Services;

use App\Models\CoverageGapAcknowledgement;
use App\Models\CoverageReservation;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\SiteCoverageRequirement;
use App\Services\ShiftSignalService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ShiftCoverageService
{
    public const DEFAULT_SLICE_MINUTES = 30;

    /**
     * Request-scoped memo for the heavy part of buildRangeCoverage() — the
     * SiteCoverageRequirement / CoverageReservation / ShiftSeries / Shift
     * queries plus the per-slice summarisation. The service is resolved
     * per-request (not bound as a singleton), so this instance cache is
     * cleared naturally at the end of each request and cannot leak stale data
     * across requests. Keyed by (siteId, rangeStart ISO, rangeEnd ISO,
     * sliceMinutes) — the full set of inputs that determine the result.
     *
     * NOTE: only the pre-lifecycle windows are cached. The acknowledgement
     * lookup (attachCoverageLifecycleMetadata) is re-run on every call so a
     * CoverageGapAcknowledgement created earlier in the same request is still
     * reflected. That lookup is a single light query over the window keys,
     * while the memoised compute is the dominant per-(shift,user) cost the
     * eligibility batch repeats.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $rangeCoverageMemo = [];

    public function __construct(
        protected CoverageRoleService $coverageRoles,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildRangeCoverage(
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        ?int $siteId = null,
        int $sliceMinutes = self::DEFAULT_SLICE_MINUTES,
    ): array {
        $memoKey = implode('|', [
            $siteId ?? 'all',
            $rangeStart->toIso8601String(),
            $rangeEnd->toIso8601String(),
            $sliceMinutes,
        ]);

        if (array_key_exists($memoKey, $this->rangeCoverageMemo)) {
            $windows = $this->rangeCoverageMemo[$memoKey];
        } else {
            $windows = $this->rangeCoverageMemo[$memoKey] = $this->computeRangeCoverage(
                $rangeStart,
                $rangeEnd,
                $siteId,
                $sliceMinutes,
            );
        }

        return $this->attachCoverageLifecycleMetadata($windows);
    }

    /**
     * Build the coverage windows for the range (without lifecycle metadata).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeRangeCoverage(
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        ?int $siteId = null,
        int $sliceMinutes = self::DEFAULT_SLICE_MINUTES,
    ): array {
        $rules = SiteCoverageRequirement::query()
            ->with([
                'site:id,name,type',
                'site.clients:id,first_name,last_name,site_id',
                'serviceContext:id,name,type',
                'preferredClient:id,first_name,last_name,site_id',
            ])
            ->active()
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->orderBy('site_id')
            ->orderBy('day_of_week')
            ->orderBy('starts_time')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $siteIds = $rules->pluck('site_id')->filter()->unique()->values()->all();
        $reservations = CoverageReservation::query()
            ->where('status', CoverageReservationService::STATUS_ACTIVE)
            ->whereIn('site_id', $siteIds)
            ->where('window_starts_at', '<', $rangeEnd)
            ->where('window_ends_at', '>', $rangeStart)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();
        $series = ShiftSeries::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'staff:id,name',
                'site:id,name,type',
                'serviceContext:id,name,type',
            ])
            ->withCount([
                'shifts as active_occurrences_count' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', $rangeStart),
                'shifts as open_occurrences_count' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->whereNull('user_id')
                    ->where('ends_at', '>=', $rangeStart),
            ])
            ->withMin([
                'shifts as next_starts_at' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', $rangeStart),
            ], 'starts_at')
            ->where('start_date', '<=', $rangeEnd->toDateString())
            ->where(function ($query) use ($rangeStart) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $rangeStart->toDateString());
            })
            ->where('status', '!=', 'cancelled')
            ->when($siteIds !== [], fn ($query) => $query->whereIn('site_id', $siteIds))
            ->get();

        $shifts = Shift::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'staff:id,name',
                'staff.hrDriverEligibility',
                'staff.medicationCompetencyAssessments',
                'serviceContext:id,name,type',
                'site:id,name,type',
            ])
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->where(function ($query) use ($siteIds) {
                $query->whereIn('site_id', $siteIds)
                    ->orWhere(function ($fallback) use ($siteIds) {
                        $fallback->whereNull('site_id')
                            ->whereHas('client', fn ($clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
                    });
            })
            ->get();

        $results = [];
        foreach ($rules as $rule) {
            foreach ($this->expandRuleOccurrences($rule, $rangeStart, $rangeEnd) as $window) {
                $matchingShifts = $shifts
                    ->filter(fn (Shift $shift) => $this->shiftMatchesRule($shift, $rule))
                    ->filter(fn (Shift $shift) => $this->intervalsOverlap(
                        $shift->starts_at,
                        $shift->ends_at,
                        $window['starts_at'],
                        $window['ends_at'],
                    ))
                    ->values();
                $matchingSeries = $series
                    ->filter(fn (ShiftSeries $row) => $this->seriesMatchesRule(
                        $row,
                        $rule,
                        $window['starts_at'],
                        $window['ends_at'],
                    ))
                    ->values();

                $results[] = $this->summarizeWindow(
                    $rule,
                    $window['starts_at'],
                    $window['ends_at'],
                    $matchingShifts,
                    $matchingSeries,
                    $reservations,
                    $sliceMinutes,
                );
            }
        }

        return collect($results)
            ->sortBy([
                ['starts_at', 'asc'],
                ['site_name', 'asc'],
                ['rule_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSiteSummaries(
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        ?int $siteId = null,
        int $sliceMinutes = self::DEFAULT_SLICE_MINUTES,
    ): array {
        return collect($this->buildRangeCoverage($rangeStart, $rangeEnd, $siteId, $sliceMinutes))
            ->groupBy('site_id')
            ->map(function (Collection $windows, $groupSiteId) {
                $siteName = (string) ($windows->first()['site_name'] ?? 'Site');
                $underCovered = $windows->filter(fn (array $window) => $this->isWindowActionable($window));
                $exact = $windows->where('coverage_state', 'exact');
                $over = $windows->where('coverage_state', 'over');

                return [
                    'site_id' => (int) $groupSiteId,
                    'site_name' => $siteName,
                    'total_windows' => $windows->count(),
                    'under_covered_windows' => $underCovered->count(),
                    'exact_windows' => $exact->count(),
                    'overstaffed_windows' => $over->count(),
                    'largest_missing_staff' => (int) $windows->max('missing_staff'),
                    'alerts' => $underCovered->take(8)->values()->all(),
                ];
            })
            ->sortByDesc('under_covered_windows')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function coverageForShift(Shift $shift, int $sliceMinutes = self::DEFAULT_SLICE_MINUTES): array
    {
        $siteId = $this->siteIdForShift($shift);
        if (! $siteId || ! $shift->starts_at || ! $shift->ends_at) {
            return [];
        }

        return collect($this->buildRangeCoverage($shift->starts_at, $shift->ends_at, $siteId, $sliceMinutes))
            ->filter(fn (array $window) => $this->intervalsOverlap(
                $shift->starts_at,
                $shift->ends_at,
                Carbon::parse($window['starts_at']),
                Carbon::parse($window['ends_at']),
            ))
            ->values()
            ->all();
    }

    public function coverageStatusForShift(Shift $shift): ?array
    {
        $windows = $this->coverageForShift($shift);
        if ($windows === []) {
            return null;
        }

        $collection = collect($windows);
        $worst = $collection
            ->sortByDesc('missing_staff')
            ->sortByDesc(fn (array $window) => ! empty($window['has_planned_role_gap']) ? 1 : 0)
            ->sortByDesc(fn (array $window) => ! empty($window['has_role_gap']) ? 1 : 0)
            ->sortBy(fn (array $window) => match ($window['coverage_state']) {
                'under' => 0,
                'exact' => 1,
                default => 2,
            })
            ->first();

        if (! $worst) {
            return null;
        }

        return [
            'site_id' => $worst['site_id'],
            'site_name' => $worst['site_name'],
            'site_client_count' => $worst['site_client_count'] ?? null,
            'site_clients' => $worst['site_clients'] ?? [],
            'rule_id' => $worst['rule_id'] ?? null,
            'coverage_window_key' => $worst['coverage_window_key'] ?? null,
            'acknowledgement' => $worst['acknowledgement'] ?? null,
            'role_shortages' => $worst['role_shortages'] ?? [],
            'planned_role_shortages' => $worst['planned_role_shortages'] ?? [],
            'preferred_client_id' => $worst['preferred_client_id'] ?? null,
            'preferred_client_name' => $worst['preferred_client_name'] ?? null,
            'allow_overstaffing' => $worst['allow_overstaffing'] ?? true,
            'coverage_state' => $worst['coverage_state'],
            'planned_coverage_state' => $worst['planned_coverage_state'] ?? $worst['coverage_state'],
            'gap_kind' => $worst['gap_kind'] ?? null,
            'recommended_fill_action' => $worst['recommended_fill_action'] ?? null,
            'has_role_gap' => $worst['has_role_gap'] ?? false,
            'has_planned_role_gap' => $worst['has_planned_role_gap'] ?? false,
            'has_actionable_gap' => $worst['has_actionable_gap'] ?? false,
            'has_actionable_imbalance' => $worst['has_actionable_imbalance'] ?? false,
            'imbalance_kind' => $worst['imbalance_kind'] ?? null,
            'contradictions' => $worst['contradictions'] ?? [],
            'partial_window_uncovered_slices' => $worst['partial_window_uncovered_slices'] ?? [],
            'starts_at' => $worst['starts_at'],
            'ends_at' => $worst['ends_at'],
            'missing_staff' => $worst['missing_staff'],
            'unfilled_after_open_shifts' => $worst['unfilled_after_open_shifts'] ?? $worst['missing_staff'],
            'required_staff' => $worst['required_staff'],
            'assigned_staff' => $worst['assigned_staff'],
            'planned_staff' => $worst['planned_staff'] ?? $worst['assigned_staff'],
            'open_shifts' => $worst['open_shifts'],
            'window_label' => $worst['window_label'],
            'contributing_shifts' => $worst['contributing_shifts'] ?? [],
            'open_shift_ids' => $worst['open_shift_ids'] ?? [],
            'matching_series' => $worst['matching_series'] ?? [],
            'recommended_fill_mode' => $worst['recommended_fill_mode'] ?? 'single',
            'coverage_slots' => $worst['coverage_slots'] ?? [],
            'matching_rules' => $windows,
        ];
    }

    public function findCoverageWindow(
        int $siteId,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
        ?int $coverageRequirementId = null,
    ): ?array {
        return collect($this->buildRangeCoverage(
            $windowStart,
            $windowEnd,
            $siteId,
        ))->first(function (array $window) use ($windowStart, $windowEnd, $coverageRequirementId) {
            if ($coverageRequirementId && (int) ($window['rule_id'] ?? 0) !== (int) $coverageRequirementId) {
                return false;
            }

            return Carbon::parse($window['starts_at'])->equalTo($windowStart)
                && Carbon::parse($window['ends_at'])->equalTo($windowEnd);
        });
    }

    protected function summarizeWindow(
        SiteCoverageRequirement $rule,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
        Collection $matchingShifts,
        Collection $matchingSeries,
        Collection $reservations,
        int $sliceMinutes,
    ): array {
        $slices = [];
        $lowestAssigned = null;
        $lowestTotal = null;
        $highestAssigned = 0;
        $roleRequirements = $this->coverageRoles->normalizeRequirements($rule->role_requirements ?? null);
        $roleSliceMinimums = [];
        $plannedRoleSliceMinimums = [];
        $uncoveredSlices = [];

        for ($cursor = $windowStart->copy(); $cursor->lt($windowEnd); $cursor = $cursor->copy()->addMinutes($sliceMinutes)) {
            $sliceEnd = $cursor->copy()->addMinutes($sliceMinutes)->min($windowEnd);

            $assignedShifts = $matchingShifts
                ->filter(fn (Shift $shift) => ! empty($shift->user_id))
                ->filter(fn (Shift $shift) => $this->intervalsOverlap(
                    $shift->starts_at,
                    $shift->ends_at,
                    $cursor,
                    $sliceEnd,
                ));
            $assigned = $assignedShifts->count();

            $openShifts = $matchingShifts
                ->filter(fn (Shift $shift) => empty($shift->user_id))
                ->filter(fn (Shift $shift) => $this->intervalsOverlap(
                    $shift->starts_at,
                    $shift->ends_at,
                    $cursor,
                    $sliceEnd,
                ));
            $open = $openShifts->count();

            $total = $assigned + $open;
            $missing = max(0, $rule->minimum_staff - $assigned);
            $roleSlices = [];

            foreach ($roleRequirements as $roleRequirement) {
                $assignedRoleCount = $assignedShifts
                    ->filter(fn (Shift $shift) => $shift->staff && $this->coverageRoles->userHasRole($shift->staff, $roleRequirement['key']))
                    ->count();
                $openRoleCount = $openShifts
                    ->filter(fn (Shift $shift) => collect($shift->coverage_roles ?? [])->contains($roleRequirement['key']))
                    ->count();
                $minimumMissing = max(0, (int) $roleRequirement['minimum'] - $assignedRoleCount);
                $roleSliceMinimums[$roleRequirement['key']] = isset($roleSliceMinimums[$roleRequirement['key']])
                    ? max($roleSliceMinimums[$roleRequirement['key']], $minimumMissing)
                    : $minimumMissing;

                $roleSlices[] = [
                    'key' => $roleRequirement['key'],
                    'label' => $roleRequirement['label'],
                    'required' => (int) $roleRequirement['minimum'],
                    'assigned' => $assignedRoleCount,
                    'planned' => $assignedRoleCount + $openRoleCount,
                    'missing' => $minimumMissing,
                    'planned_missing' => max(0, (int) $roleRequirement['minimum'] - ($assignedRoleCount + $openRoleCount)),
                ];

                $plannedMinimumMissing = max(0, (int) $roleRequirement['minimum'] - ($assignedRoleCount + $openRoleCount));
                $plannedRoleSliceMinimums[$roleRequirement['key']] = isset($plannedRoleSliceMinimums[$roleRequirement['key']])
                    ? max($plannedRoleSliceMinimums[$roleRequirement['key']], $plannedMinimumMissing)
                    : $plannedMinimumMissing;
            }

            $lowestAssigned = $lowestAssigned === null ? $assigned : min($lowestAssigned, $assigned);
            $lowestTotal = $lowestTotal === null ? $total : min($lowestTotal, $total);
            $highestAssigned = max($highestAssigned, $assigned);

            if ($missing > 0) {
                $uncoveredSlices[] = [
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $sliceEnd->toIso8601String(),
                    'missing_staff' => $missing,
                ];
            }

            $slices[] = [
                'starts_at' => $cursor->toIso8601String(),
                'ends_at' => $sliceEnd->toIso8601String(),
                'required_staff' => (int) $rule->minimum_staff,
                'assigned_staff' => $assigned,
                'open_shifts' => $open,
                'missing_staff' => $missing,
                'role_slices' => $roleSlices,
            ];
        }

        $lowestAssigned ??= 0;
        $lowestTotal ??= 0;
        $partialWindowUncoveredSlices = $this->mergeMissingSlices($uncoveredSlices);

        $coverageState = 'exact';
        if ($lowestAssigned < (int) $rule->minimum_staff) {
            $coverageState = 'under';
        } elseif ($lowestAssigned > (int) $rule->minimum_staff) {
            $coverageState = 'over';
        }

        $plannedCoverageState = 'exact';
        if ($lowestTotal < (int) $rule->minimum_staff) {
            $plannedCoverageState = 'under';
        } elseif ($lowestTotal > (int) $rule->minimum_staff) {
            $plannedCoverageState = 'over';
        }

        $contributingShifts = $matchingShifts
            ->sortBy('starts_at')
            ->map(fn (Shift $shift) => $this->serializeCoverageShift($shift))
            ->values()
            ->all();
        $openShiftIds = collect($contributingShifts)
            ->where('is_open', true)
            ->pluck('id')
            ->values()
            ->all();
        $linkedSeries = $matchingSeries
            ->sortBy('next_starts_at')
            ->map(fn (ShiftSeries $series) => $this->serializeCoverageSeries($series))
            ->values()
            ->all();
        $matchingReservations = $reservations
            ->filter(fn (CoverageReservation $reservation) => (int) $reservation->site_id === (int) $rule->site_id)
            ->filter(fn (CoverageReservation $reservation) => (int) ($reservation->coverage_requirement_id ?? 0) === (int) $rule->id)
            ->filter(fn (CoverageReservation $reservation) => $reservation->window_starts_at?->equalTo($windowStart))
            ->filter(fn (CoverageReservation $reservation) => $reservation->window_ends_at?->equalTo($windowEnd))
            ->values();
        $unfilledAfterOpenShifts = max(0, (int) $rule->minimum_staff - $lowestTotal);
        $roleShortages = collect($roleRequirements)
            ->map(function (array $roleRequirement) use ($roleSliceMinimums) {
                $missing = (int) ($roleSliceMinimums[$roleRequirement['key']] ?? 0);

                return [
                    'key' => $roleRequirement['key'],
                    'label' => $roleRequirement['label'],
                    'required' => (int) $roleRequirement['minimum'],
                    'missing' => $missing,
                ];
            })
            ->filter(fn (array $roleRequirement) => $roleRequirement['missing'] > 0)
            ->values()
            ->all();
        $plannedRoleShortages = collect($roleRequirements)
            ->map(function (array $roleRequirement) use ($plannedRoleSliceMinimums) {
                $missing = (int) ($plannedRoleSliceMinimums[$roleRequirement['key']] ?? 0);

                return [
                    'key' => $roleRequirement['key'],
                    'label' => $roleRequirement['label'],
                    'required' => (int) $roleRequirement['minimum'],
                    'missing' => $missing,
                ];
            })
            ->filter(fn (array $roleRequirement) => $roleRequirement['missing'] > 0)
            ->values()
            ->all();
        $hasRoleGap = $roleShortages !== [];
        $hasPlannedRoleGap = $plannedRoleShortages !== [];
        $gapKind = $this->determineGapKind(
            max(0, (int) $rule->minimum_staff - $lowestAssigned),
            $unfilledAfterOpenShifts,
            $hasRoleGap,
            $hasPlannedRoleGap,
            $coverageState,
            $plannedCoverageState,
            (bool) $rule->allow_overstaffing,
        );
        $imbalanceKind = $this->determineImbalanceKind(
            $coverageState,
            $plannedCoverageState,
            (bool) $rule->allow_overstaffing,
            $hasRoleGap,
            $hasPlannedRoleGap,
        );
        $contradictions = $this->buildContradictions(
            $rule,
            $coverageState,
            $plannedCoverageState,
            $hasRoleGap,
            $hasPlannedRoleGap,
            $contributingShifts,
            $linkedSeries,
            $imbalanceKind,
            $highestAssigned,
        );
        $fillIntent = $this->buildFillIntent(
            $rule,
            $windowStart,
            $windowEnd,
            $gapKind ?? $imbalanceKind,
            $plannedRoleShortages !== [] ? $plannedRoleShortages : $roleShortages,
            $linkedSeries !== [],
        );
        $coverageSlots = $this->buildCoverageSlots(
            $rule,
            $lowestAssigned,
            max(0, $lowestTotal - $lowestAssigned),
            $roleRequirements,
            $roleShortages,
            $plannedRoleShortages,
            $matchingReservations,
            $windowStart,
            $windowEnd,
        );
        $siteClients = collect($rule->site?->clients ?? [])->sortBy('first_name')->values();

        return [
            'site_id' => $rule->site_id,
            'site_name' => $rule->site?->name ?? 'Site',
            'site_client_count' => $siteClients->count(),
            'site_clients' => $siteClients->map(fn ($client) => [
                'id' => $client->id,
                'name' => trim($client->first_name.' '.$client->last_name),
            ])->values()->all(),
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'preferred_client_id' => $rule->preferred_client_id,
            'preferred_client_name' => $rule->preferredClient
                ? trim($rule->preferredClient->first_name.' '.$rule->preferredClient->last_name)
                : null,
            'coverage_type' => $rule->coverage_type,
            'service_context_id' => $rule->service_context_id,
            'service_context_name' => $rule->serviceContext?->name,
            'shift_type' => $rule->shift_type,
            'role_requirements' => $roleRequirements,
            'role_shortages' => $roleShortages,
            'planned_role_shortages' => $plannedRoleShortages,
            'has_role_gap' => $hasRoleGap,
            'has_planned_role_gap' => $hasPlannedRoleGap,
            'allow_overstaffing' => (bool) $rule->allow_overstaffing,
            'starts_at' => $windowStart->toIso8601String(),
            'ends_at' => $windowEnd->toIso8601String(),
            'window_label' => $windowStart->format('D j M g:i A').' - '.$windowEnd->format(
                $windowStart->toDateString() === $windowEnd->toDateString() ? 'g:i A' : 'D j M g:i A',
            ),
            'required_staff' => (int) $rule->minimum_staff,
            'assigned_staff' => $lowestAssigned,
            'open_shifts' => max(0, $lowestTotal - $lowestAssigned),
            'max_assigned_staff' => $highestAssigned,
            'missing_staff' => max(0, (int) $rule->minimum_staff - $lowestAssigned),
            'planned_staff' => $lowestTotal,
            'unfilled_after_open_shifts' => $unfilledAfterOpenShifts,
            'coverage_state' => $coverageState,
            'planned_coverage_state' => $plannedCoverageState,
            'gap_kind' => $gapKind ?? $imbalanceKind,
            'imbalance_kind' => $imbalanceKind,
            'recommended_fill_action' => $fillIntent['action'],
            'recommended_fill_roles' => $fillIntent['roles'],
            'fill_intent' => $fillIntent,
            'has_actionable_gap' => $gapKind !== null,
            'has_actionable_imbalance' => $imbalanceKind !== null,
            'contradictions' => $contradictions,
            'partial_window_uncovered_slices' => $partialWindowUncoveredSlices,
            'coverage_slots' => $coverageSlots,
            'slice_minutes' => $sliceMinutes,
            'slices' => $slices,
            'contributing_shifts' => $contributingShifts,
            'open_shift_ids' => $openShiftIds,
            'matching_series' => $linkedSeries,
            'matching_series_count' => count($linkedSeries),
            'recommended_fill_mode' => $linkedSeries !== [] ? 'recurring' : 'single',
        ];
    }

    /**
     * @return array<int, array{starts_at: CarbonInterface, ends_at: CarbonInterface}>
     */
    protected function expandRuleOccurrences(
        SiteCoverageRequirement $rule,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
    ): array {
        $occurrences = [];
        $cursor = $rangeStart->copy()->startOfDay();
        $endDay = $rangeEnd->copy()->endOfDay();

        while ($cursor->lte($endDay)) {
            if ($this->weekdayCode($cursor) !== $rule->day_of_week) {
                $cursor = $cursor->addDay();
                continue;
            }

            $windowStart = Carbon::parse($cursor->toDateString().' '.$rule->starts_time, $cursor->timezone);
            $windowEnd = Carbon::parse($cursor->toDateString().' '.$rule->ends_time, $cursor->timezone);

            if (! $windowEnd->greaterThan($windowStart)) {
                $windowEnd = $windowEnd->addDay();
            }

            if ($this->intervalsOverlap($windowStart, $windowEnd, $rangeStart, $rangeEnd)) {
                $occurrences[] = [
                    'starts_at' => $windowStart->max($rangeStart),
                    'ends_at' => $windowEnd->min($rangeEnd),
                ];
            }

            $cursor = $cursor->addDay();
        }

        return $occurrences;
    }

    /**
     * @param array<int, array<string, mixed>> $windows
     * @return array<int, array<string, mixed>>
     */
    protected function attachCoverageLifecycleMetadata(array $windows): array
    {
        if ($windows === []) {
            return [];
        }

        $signalService = app(ShiftSignalService::class);
        $windows = collect($windows)
            ->map(function (array $window) use ($signalService) {
                $key = $window['coverage_window_key'] ?? $signalService->buildCoverageWindowKey($window);

                return array_merge($window, [
                    'coverage_window_key' => $key,
                    'acknowledgement' => null,
                ]);
            })
            ->values();

        $acknowledgements = CoverageGapAcknowledgement::query()
            ->with('actor:id,name')
            ->whereIn('site_id', $windows->pluck('site_id')->map(fn ($siteId) => (int) $siteId)->unique()->all())
            ->whereIn('coverage_window_key', $windows->pluck('coverage_window_key')->all())
            ->whereNull('cleared_at')
            ->orderBy('created_at')
            ->get()
            ->keyBy('coverage_window_key');

        return $windows
            ->map(function (array $window) use ($acknowledgements) {
                $acknowledgement = $acknowledgements->get($window['coverage_window_key']);

                return array_merge($window, [
                    'acknowledgement' => $this->serializeAcknowledgement($acknowledgement),
                ]);
            })
            ->all();
    }

    protected function serializeAcknowledgement(?CoverageGapAcknowledgement $acknowledgement): ?array
    {
        if (! $acknowledgement) {
            return null;
        }

        return [
            'state' => $acknowledgement->state,
            'actor' => $acknowledgement->actor ? [
                'id' => $acknowledgement->actor->id,
                'name' => $acknowledgement->actor->name,
            ] : null,
            'reason' => $acknowledgement->reason,
            'since' => $acknowledgement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<int, array{starts_at: string, ends_at: string, missing_staff: int}> $slices
     * @return array<int, array{starts_at: string, ends_at: string, missing_staff: int}>
     */
    protected function mergeMissingSlices(array $slices): array
    {
        $merged = [];

        foreach ($slices as $slice) {
            $lastIndex = count($merged) - 1;
            $last = $lastIndex >= 0 ? $merged[$lastIndex] : null;

            if (
                $last
                && (int) $last['missing_staff'] === (int) $slice['missing_staff']
                && Carbon::parse($last['ends_at'])->equalTo(Carbon::parse($slice['starts_at']))
            ) {
                $merged[$lastIndex]['ends_at'] = $slice['ends_at'];
                continue;
            }

            $merged[] = [
                'starts_at' => $slice['starts_at'],
                'ends_at' => $slice['ends_at'],
                'missing_staff' => (int) $slice['missing_staff'],
            ];
        }

        return $merged;
    }

    protected function isWindowActionable(array $window): bool
    {
        if (! empty($window['has_actionable_gap']) || ! empty($window['has_actionable_imbalance'])) {
            return true;
        }

        if ((int) ($window['unfilled_after_open_shifts'] ?? 0) > 0) {
            return true;
        }

        if (! empty($window['partial_window_uncovered_slices'] ?? [])) {
            return true;
        }

        if (in_array('partial_window_undercoverage', $window['contradictions'] ?? [], true)) {
            return true;
        }

        return collect($window['role_shortages'] ?? [])
            ->contains(fn (array $shortage) => (int) ($shortage['missing'] ?? 0) > 0);
    }

    protected function shiftMatchesRule(Shift $shift, SiteCoverageRequirement $rule): bool
    {
        if ($this->siteIdForShift($shift) !== (int) $rule->site_id) {
            return false;
        }

        if ($rule->service_context_id && (int) $shift->service_context_id !== (int) $rule->service_context_id) {
            return false;
        }

        if ($rule->shift_type && ($shift->shift_type ?? 'standard') !== $rule->shift_type) {
            return false;
        }

        return true;
    }

    protected function seriesMatchesRule(
        ShiftSeries $series,
        SiteCoverageRequirement $rule,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): bool {
        if ((int) ($series->site_id ?? 0) !== (int) $rule->site_id) {
            return false;
        }

        if ($rule->service_context_id && (int) $series->service_context_id !== (int) $rule->service_context_id) {
            return false;
        }

        if ($rule->shift_type && ($series->shift_type ?? 'standard') !== $rule->shift_type) {
            return false;
        }

        $weekdays = collect($series->by_weekday ?? [])->filter()->values()->all();
        if ($weekdays !== [] && ! in_array($this->weekdayCode($windowStart), $weekdays, true)) {
            return false;
        }

        if ($series->start_date && $windowEnd->lt($series->start_date->copy()->startOfDay())) {
            return false;
        }

        if ($series->end_date && $windowStart->gt($series->end_date->copy()->endOfDay())) {
            return false;
        }

        if (! $series->starts_time || ! $series->ends_time) {
            return true;
        }

        $seriesStart = Carbon::parse($windowStart->toDateString().' '.$series->starts_time, $windowStart->timezone);
        $seriesEnd = Carbon::parse($windowStart->toDateString().' '.$series->ends_time, $windowStart->timezone);

        if (! $seriesEnd->greaterThan($seriesStart)) {
            $seriesEnd = $seriesEnd->addDay();
        }

        return $this->intervalsOverlap($seriesStart, $seriesEnd, $windowStart, $windowEnd);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildRecurringAlignment(
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        ?int $siteId = null,
        int $sliceMinutes = self::DEFAULT_SLICE_MINUTES,
    ): array {
        $windows = collect($this->buildRangeCoverage($rangeStart, $rangeEnd, $siteId, $sliceMinutes));

        $ruleDrift = $windows
            ->filter(function (array $window) {
                if (($window['matching_series_count'] ?? 0) === 0) {
                    return true;
                }

                if (! empty($window['has_planned_role_gap'])) {
                    return true;
                }

                return (($window['coverage_state'] ?? null) === 'under'
                    || ! empty($window['has_actionable_gap']))
                    && (count($window['contributing_shifts'] ?? []) < ($window['matching_series_count'] ?? 0)
                        || ! empty($window['has_planned_role_gap']));
            })
            ->map(function (array $window) {
                return [
                    'site_id' => $window['site_id'],
                    'site_name' => $window['site_name'],
                    'rule_id' => $window['rule_id'],
                    'rule_name' => $window['rule_name'],
                    'window_label' => $window['window_label'],
                    'starts_at' => $window['starts_at'],
                    'ends_at' => $window['ends_at'],
                    'required_staff' => $window['required_staff'],
                    'assigned_staff' => $window['assigned_staff'],
                    'open_shifts' => $window['open_shifts'],
                    'missing_staff' => $window['missing_staff'],
                    'unfilled_after_open_shifts' => $window['unfilled_after_open_shifts'] ?? $window['missing_staff'],
                    'issue_type' => ($window['matching_series_count'] ?? 0) === 0
                        ? 'demand_without_recurring_supply'
                        : (! empty($window['has_planned_role_gap'])
                            ? 'recurring_role_drift'
                            : 'recurring_supply_drift'),
                    'role_shortages' => $window['role_shortages'] ?? [],
                    'planned_role_shortages' => $window['planned_role_shortages'] ?? [],
                    'gap_kind' => $window['gap_kind'] ?? null,
                    'imbalance_kind' => $window['imbalance_kind'] ?? null,
                    'contradictions' => $window['contradictions'] ?? [],
                    'matching_series' => $window['matching_series'] ?? [],
                    'contributing_shifts' => $window['contributing_shifts'] ?? [],
                ];
            })
            ->values();

        $futureSeries = ShiftSeries::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'site:id,name,type',
                'staff:id,name',
                'serviceContext:id,name,type',
            ])
            ->withCount([
                'shifts as active_occurrences_count' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', $rangeStart),
            ])
            ->withMin([
                'shifts as next_starts_at' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', $rangeStart),
            ], 'starts_at')
            ->where('status', '!=', 'cancelled')
            ->where('start_date', '<=', $rangeEnd->toDateString())
            ->where(function ($query) use ($rangeStart) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $rangeStart->toDateString());
            })
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->get();

        $orphanSeries = $futureSeries
            ->filter(function (ShiftSeries $series) use ($windows) {
                return ! $windows->contains(fn (array $window) => collect($window['matching_series'] ?? [])
                    ->contains(fn (array $row) => (int) ($row['id'] ?? 0) === (int) $series->id));
            })
            ->map(fn (ShiftSeries $series) => [
                'series_id' => $series->id,
                'site_id' => $series->site_id,
                'site_name' => $series->site?->name ?? 'Site',
                'client_name' => trim((string) ($series->client?->first_name.' '.$series->client?->last_name)) ?: null,
                'staff_name' => $series->staff?->name,
                'service_context_name' => $series->serviceContext?->name,
                'shift_type' => $series->shift_type ?? 'standard',
                'weekdays' => $series->by_weekday ?? [],
                'starts_time' => $series->starts_time,
                'ends_time' => $series->ends_time,
                'next_starts_at' => $series->next_starts_at ? Carbon::parse($series->next_starts_at)->toIso8601String() : null,
                'active_occurrences_count' => (int) ($series->active_occurrences_count ?? 0),
                'issue_type' => 'recurring_supply_without_demand',
            ])
            ->values();

        return [
            'rule_drift' => $ruleDrift->all(),
            'orphan_series' => $orphanSeries->all(),
        ];
    }

    protected function siteIdForShift(Shift $shift): ?int
    {
        return $shift->site_id ?: $shift->client?->site_id;
    }

    protected function intervalsOverlap(
        ?CarbonInterface $firstStart,
        ?CarbonInterface $firstEnd,
        ?CarbonInterface $secondStart,
        ?CarbonInterface $secondEnd,
    ): bool {
        if (! $firstStart || ! $firstEnd || ! $secondStart || ! $secondEnd) {
            return false;
        }

        return $firstStart->lt($secondEnd) && $secondStart->lt($firstEnd);
    }

    protected function weekdayCode(CarbonInterface $date): string
    {
        return strtolower($date->format('D'));
    }

    protected function serializeCoverageShift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'client_id' => $shift->client_id,
            'client_name' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : 'Client',
            'staff_name' => $shift->staff?->name,
            'status' => $shift->status,
            'location' => $shift->location,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'shift_series_id' => $shift->shift_series_id,
            'is_open' => empty($shift->user_id),
            'coverage_roles' => $shift->coverage_roles ?? [],
        ];
    }

    protected function serializeCoverageSeries(ShiftSeries $series): array
    {
        return [
            'id' => $series->id,
            'client_id' => $series->client_id,
            'client_name' => trim((string) ($series->client?->first_name.' '.$series->client?->last_name)) ?: null,
            'staff_name' => $series->staff?->name,
            'service_context_name' => $series->serviceContext?->name,
            'shift_type' => $series->shift_type ?? 'standard',
            'weekdays' => $series->by_weekday ?? [],
            'starts_time' => $series->starts_time,
            'ends_time' => $series->ends_time,
            'location' => $series->location,
            'next_starts_at' => $series->next_starts_at ? Carbon::parse($series->next_starts_at)->toIso8601String() : null,
            'active_occurrences_count' => (int) ($series->active_occurrences_count ?? 0),
            'open_occurrences_count' => (int) ($series->open_occurrences_count ?? 0),
            'coverage_roles' => $series->coverage_roles ?? [],
        ];
    }

    protected function determineGapKind(
        int $missingStaff,
        int $unfilledAfterOpenShifts,
        bool $hasRoleGap,
        bool $hasPlannedRoleGap,
        string $coverageState,
        string $plannedCoverageState,
        bool $allowOverstaffing,
    ): ?string {
        if (
            ($coverageState === 'over' || $plannedCoverageState === 'over')
            && (! $allowOverstaffing || $hasRoleGap || $hasPlannedRoleGap)
        ) {
            return null;
        }

        if ($unfilledAfterOpenShifts > 0 && $hasPlannedRoleGap) {
            return 'mixed_unplanned';
        }

        if ($unfilledAfterOpenShifts > 0) {
            return 'headcount_unplanned';
        }

        if ($missingStaff > 0 && $hasPlannedRoleGap) {
            return 'mixed_open';
        }

        if ($missingStaff > 0) {
            return 'headcount_open';
        }

        if ($hasPlannedRoleGap) {
            return 'role_unplanned';
        }

        if ($hasRoleGap) {
            return 'role_open';
        }

        return null;
    }

    protected function determineImbalanceKind(
        string $coverageState,
        string $plannedCoverageState,
        bool $allowOverstaffing,
        bool $hasRoleGap,
        bool $hasPlannedRoleGap,
    ): ?string {
        if (($coverageState === 'over' || $plannedCoverageState === 'over') && ! $allowOverstaffing) {
            return $hasRoleGap || $hasPlannedRoleGap
                ? 'overfill_and_role_imbalance'
                : 'overfill_not_allowed';
        }

        if (($coverageState === 'over' || $plannedCoverageState === 'over') && ($hasRoleGap || $hasPlannedRoleGap)) {
            return 'overfilled_wrong_role_mix';
        }

        return null;
    }

    protected function buildContradictions(
        SiteCoverageRequirement $rule,
        string $coverageState,
        string $plannedCoverageState,
        bool $hasRoleGap,
        bool $hasPlannedRoleGap,
        array $contributingShifts,
        array $linkedSeries,
        ?string $imbalanceKind = null,
        int $highestAssigned = 0,
    ): array {
        $contradictions = [];

        if ($coverageState === 'exact' && $hasRoleGap) {
            $contradictions[] = 'headcount_exact_but_role_gap';
        }

        if ($plannedCoverageState === 'exact' && $hasPlannedRoleGap) {
            $contradictions[] = 'planned_supply_exact_but_role_gap';
        }

        if ($rule->preferred_client_id) {
            $preferredMatched = collect($contributingShifts)->contains(fn (array $shift) => (int) ($shift['client_id'] ?? 0) === (int) $rule->preferred_client_id)
                || collect($linkedSeries)->contains(fn (array $series) => (int) ($series['client_id'] ?? 0) === (int) $rule->preferred_client_id);

            if (($contributingShifts !== [] || $linkedSeries !== []) && ! $preferredMatched) {
                $contradictions[] = 'preferred_client_drift';
            }
        }

        if ($highestAssigned >= (int) $rule->minimum_staff && $coverageState === 'under') {
            $contradictions[] = 'partial_window_undercoverage';
        }

        if ($imbalanceKind === 'overfill_not_allowed') {
            $contradictions[] = 'overfill_not_allowed';
        }

        if (in_array($imbalanceKind, ['overfilled_wrong_role_mix', 'overfill_and_role_imbalance'], true)) {
            $contradictions[] = 'overfilled_but_wrong_role_mix';
        }

        return $contradictions;
    }

    protected function buildFillIntent(
        SiteCoverageRequirement $rule,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
        ?string $gapKind,
        array $roles,
        bool $hasMatchingSeries,
    ): array {
        $action = match ($gapKind) {
            'headcount_open', 'role_open' => 'fill_existing_open_shift',
            'mixed_open' => 'retag_or_replace_open_shift',
            'role_unplanned' => 'create_role_specific_shift',
            'mixed_unplanned' => 'create_role_specific_shift',
            'headcount_unplanned' => $hasMatchingSeries ? 'create_recurring_cover' : 'create_cover_shift',
            'overfill_not_allowed' => 'review_existing_supply',
            'overfilled_wrong_role_mix', 'overfill_and_role_imbalance' => 'rebalance_existing_supply',
            default => 'none',
        };

        return [
            'action' => $action,
            'site_id' => $rule->site_id,
            'coverage_rule_id' => $rule->id,
            'preferred_client_id' => $rule->preferred_client_id,
            'window_starts_at' => $windowStart->toIso8601String(),
            'window_ends_at' => $windowEnd->toIso8601String(),
            'site_name' => $rule->site?->name ?? 'Site',
            'preferred_client_name' => $rule->preferredClient
                ? trim($rule->preferredClient->first_name.' '.$rule->preferredClient->last_name)
                : null,
            'roles' => array_values(array_map(
                fn (array $role) => [
                    'key' => $role['key'],
                    'label' => $role['label'] ?? $role['key'],
                    'missing' => (int) ($role['missing'] ?? 0),
                ],
                $roles,
            )),
        ];
    }

    protected function buildCoverageSlots(
        SiteCoverageRequirement $rule,
        int $assignedStaff,
        int $openShiftCount,
        array $roleRequirements,
        array $roleShortages,
        array $plannedRoleShortages,
        Collection $reservations,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): array {
        $slots = [];
        $genericReservations = $reservations
            ->filter(fn (CoverageReservation $reservation) => empty($reservation->role_key))
            ->count();

        for ($index = 1; $index <= (int) $rule->minimum_staff; $index++) {
            $status = 'available';
            if ($index <= $assignedStaff) {
                $status = 'assigned';
            } elseif ($index <= ($assignedStaff + $openShiftCount)) {
                $status = 'open_shift';
            } elseif ($index <= ($assignedStaff + $openShiftCount + $genericReservations)) {
                $status = 'reserved';
            }

            $slots[] = [
                'slot_key' => $this->slotKey($rule->id, $windowStart, $windowEnd, null, $index),
                'kind' => 'headcount',
                'label' => 'Coverage slot '.$index,
                'status' => $status,
            ];
        }

        foreach ($roleRequirements as $roleRequirement) {
            $missingAssigned = (int) (collect($roleShortages)->firstWhere('key', $roleRequirement['key'])['missing'] ?? 0);
            $missingPlanned = (int) (collect($plannedRoleShortages)->firstWhere('key', $roleRequirement['key'])['missing'] ?? 0);
            $assignedRoleCount = max(0, (int) $roleRequirement['minimum'] - $missingAssigned);
            $plannedRoleCount = max(0, (int) $roleRequirement['minimum'] - $missingPlanned);
            $reservedRoleCount = $reservations
                ->filter(fn (CoverageReservation $reservation) => $reservation->role_key === $roleRequirement['key'])
                ->count();

            for ($index = 1; $index <= (int) $roleRequirement['minimum']; $index++) {
                $status = 'available';
                if ($index <= $assignedRoleCount) {
                    $status = 'assigned';
                } elseif ($index <= $plannedRoleCount) {
                    $status = 'open_shift';
                } elseif ($index <= ($plannedRoleCount + $reservedRoleCount)) {
                    $status = 'reserved';
                }

                $slots[] = [
                    'slot_key' => $this->slotKey($rule->id, $windowStart, $windowEnd, $roleRequirement['key'], $index),
                    'kind' => 'role',
                    'role_key' => $roleRequirement['key'],
                    'label' => $roleRequirement['label'],
                    'status' => $status,
                ];
            }
        }

        return $slots;
    }

    protected function slotKey(
        int $ruleId,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
        ?string $roleKey,
        int $index,
    ): string {
        return implode(':', [
            'coverage',
            $ruleId,
            $windowStart->format('YmdHi'),
            $windowEnd->format('YmdHi'),
            $roleKey ?: 'headcount',
            $index,
        ]);
    }
}
