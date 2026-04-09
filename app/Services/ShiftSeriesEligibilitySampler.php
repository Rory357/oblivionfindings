<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Samples a bounded set of occurrences from a recurring shift series
 * and evaluates staff eligibility against each.
 *
 * Strategy:
 *   - Always check first, last, and a mid-point occurrence
 *   - For series spanning multiple weeks, add one checkpoint per unique
 *     ISO week (capped at MAX_SAMPLES) to catch fatigue accumulation
 *   - Reuses ShiftStaffEligibilityService for each check
 *
 * This is a risk-reduction measure, not a guarantee — occurrences between
 * samples may still fail. The nightly RecalculateFutureShiftEligibility
 * job catches remaining issues after creation.
 */
class ShiftSeriesEligibilitySampler
{
    /**
     * Maximum number of occurrences to evaluate regardless of series length.
     */
    public const MAX_SAMPLES = 8;

    public function __construct(
        protected ShiftStaffEligibilityService $eligibility,
    ) {
    }

    /**
     * Evaluate sampled occurrences for eligibility blocks and warnings.
     *
     * @param  array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $occurrenceWindows
     * @param  array<string, mixed>  $shiftTemplate  Shared shift attributes (user_id, site_id, etc.)
     * @return array{
     *     passed: bool,
     *     blocked_at: array{date: string, reasons: string[]}|null,
     *     warnings: array<int, array{date: string, messages: string[]}>,
     *     sampled_count: int,
     *     total_count: int,
     * }
     */
    public function evaluate(array $occurrenceWindows, User $assignee, array $shiftTemplate): array
    {
        $total = count($occurrenceWindows);

        if ($total === 0) {
            return $this->result(true, null, [], 0, 0);
        }

        $sampleIndices = $this->selectSampleIndices($occurrenceWindows);
        $warnings = [];

        foreach ($sampleIndices as $index) {
            $window = $occurrenceWindows[$index];

            $tempShift = new Shift(array_merge($shiftTemplate, [
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
            ]));

            $eligibility = $this->eligibility->evaluate($tempShift, $assignee);

            if ($eligibility->hasBlocks()) {
                return $this->result(
                    passed: false,
                    blockedAt: [
                        'date' => $window['starts_at']->format('D j M Y'),
                        'reasons' => $eligibility->blocking_reasons,
                    ],
                    warnings: $warnings,
                    sampledCount: count($sampleIndices),
                    totalCount: $total,
                );
            }

            if ($eligibility->hasWarnings()) {
                $warnings[] = [
                    'date' => $window['starts_at']->format('D j M Y'),
                    'messages' => $eligibility->warnings,
                ];
            }
        }

        return $this->result(
            passed: true,
            blockedAt: null,
            warnings: $warnings,
            sampledCount: count($sampleIndices),
            totalCount: $total,
        );
    }

    /**
     * Select a bounded set of representative occurrence indices.
     *
     * Always includes first, mid, and last. For multi-week series, adds
     * one sample per unique ISO week to catch cumulative fatigue.
     *
     * @param  array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $windows
     * @return int[]
     */
    public function selectSampleIndices(array $windows): array
    {
        $total = count($windows);

        if ($total <= self::MAX_SAMPLES) {
            return range(0, $total - 1);
        }

        $indices = [];

        // Always: first.
        $indices[] = 0;

        // One per unique ISO week (prefer the last occurrence in each week
        // to catch end-of-week fatigue accumulation).
        $weekBuckets = [];
        foreach ($windows as $i => $w) {
            $weekKey = $w['starts_at']->isoWeekYear . '-W' . str_pad((string) $w['starts_at']->isoWeek, 2, '0', STR_PAD_LEFT);
            $weekBuckets[$weekKey] = $i; // last occurrence in each week wins
        }

        foreach ($weekBuckets as $idx) {
            $indices[] = $idx;
        }

        // Always: mid and last.
        $indices[] = (int) floor($total / 2);
        $indices[] = $total - 1;

        // Deduplicate and sort, then cap — but always keep first and last.
        $indices = array_values(array_unique($indices));
        sort($indices);

        if (count($indices) <= self::MAX_SAMPLES) {
            return $indices;
        }

        // Ensure first and last are always in the final set.
        $first = $indices[0];
        $last = $indices[count($indices) - 1];
        $middle = array_slice($indices, 1, count($indices) - 2);

        // Take evenly spaced samples from the middle to fill remaining slots.
        $remainingSlots = self::MAX_SAMPLES - 2; // minus first and last
        $step = max(1, (int) ceil(count($middle) / $remainingSlots));
        $selectedMiddle = [];
        for ($i = 0; $i < count($middle) && count($selectedMiddle) < $remainingSlots; $i += $step) {
            $selectedMiddle[] = $middle[$i];
        }

        $final = array_merge([$first], $selectedMiddle, [$last]);
        sort($final);

        return array_values(array_unique($final));
    }

    /**
     * @return array{passed: bool, blocked_at: array|null, warnings: array, sampled_count: int, total_count: int}
     */
    protected function result(bool $passed, ?array $blockedAt, array $warnings, int $sampledCount, int $totalCount): array
    {
        return [
            'passed' => $passed,
            'blocked_at' => $blockedAt,
            'warnings' => $warnings,
            'sampled_count' => $sampledCount,
            'total_count' => $totalCount,
        ];
    }
}
