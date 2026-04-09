<?php

namespace App\Services\Eligibility\Rules;

use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Enforces fatigue / safe-rostering thresholds from config('hr.fatigue').
 *
 * Sub-checks:
 *   fatigue_daily       — max hours in a single calendar day
 *   fatigue_weekly      — max hours in an ISO week (block) / warning threshold
 *   fatigue_rest        — minimum rest gap between consecutive shifts
 *   fatigue_consecutive — maximum consecutive calendar days worked
 *
 * All queries exclude cancelled shifts and the shift being evaluated
 * (if it already has an ID).
 */
class FatigueRule implements EligibilityRuleInterface
{
    /**
     * Single entry point required by interface — returns the first failing sub-check.
     */
    public function evaluate(Shift $shift, User $user): array
    {
        foreach ($this->evaluateAll($shift, $user) as $result) {
            if (! $result['passed']) {
                return $result;
            }
        }

        return self::pass('fatigue');
    }

    /**
     * Return all four sub-check results for inclusion in checked_rules.
     *
     * @return array<int, array{rule: string, passed: bool, severity: string, overrideable: bool, message: ?string}>
     */
    public function evaluateAll(Shift $shift, User $user): array
    {
        $startsAt = $this->resolveCarbon($shift->starts_at);
        $endsAt = $this->resolveCarbon($shift->ends_at);

        if (! $startsAt || ! $endsAt) {
            return [
                self::pass('fatigue_daily'),
                self::pass('fatigue_weekly'),
                self::pass('fatigue_rest'),
                self::pass('fatigue_consecutive'),
            ];
        }

        $shiftHours = $startsAt->floatDiffInHours($endsAt);

        return [
            $this->checkDailyHours($user, $startsAt, $endsAt, $shiftHours, $shift->id),
            $this->checkWeeklyHours($user, $startsAt, $shiftHours, $shift->id),
            $this->checkMinRestGap($user, $startsAt, $endsAt, $shift->id),
            $this->checkConsecutiveDays($user, $startsAt, $shift->id),
        ];
    }

    /**
     * Daily hours: total hours on each calendar day the shift spans must not exceed the max.
     */
    protected function checkDailyHours(User $user, CarbonInterface $startsAt, CarbonInterface $endsAt, float $shiftHours, ?int $ignoreShiftId): array
    {
        $maxDaily = (float) config('hr.fatigue.max_hours_per_day', 12);

        // Get all dates the shift touches.
        $dates = $this->calendarDatesSpanned($startsAt, $endsAt);

        foreach ($dates as $date) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $existingHours = $this->sumShiftHoursInWindow($user->id, $dayStart, $dayEnd, $ignoreShiftId);

            // Calculate how much of THIS shift falls on this calendar day.
            $overlapStart = $startsAt->max($dayStart);
            $overlapEnd = $endsAt->min($dayEnd);
            $thisShiftDayHours = $overlapStart->floatDiffInHours($overlapEnd);

            $totalDay = $existingHours + $thisShiftDayHours;

            if ($totalDay > $maxDaily) {
                return [
                    'rule' => 'fatigue_daily',
                    'passed' => false,
                    'severity' => 'block',
                    'overrideable' => false,
                    'message' => "Would exceed {$maxDaily}h daily maximum ({$this->fmt($totalDay)}h total on {$date->format('D j M')}).",
                ];
            }
        }

        return self::pass('fatigue_daily');
    }

    /**
     * Weekly hours: total hours in the ISO week must not exceed max (block) / warning threshold.
     */
    protected function checkWeeklyHours(User $user, CarbonInterface $startsAt, float $shiftHours, ?int $ignoreShiftId): array
    {
        $maxWeekly = (float) config('hr.fatigue.max_hours_per_week', 50);
        $warningWeekly = (float) config('hr.fatigue.warning_threshold_weekly', 40);

        $weekStart = $startsAt->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $existingHours = $this->sumShiftHoursInWindow($user->id, $weekStart, $weekEnd, $ignoreShiftId);
        $totalWeek = $existingHours + $shiftHours;

        if ($totalWeek > $maxWeekly) {
            return [
                'rule' => 'fatigue_weekly',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => "Would exceed {$maxWeekly}h weekly maximum ({$this->fmt($totalWeek)}h total).",
            ];
        }

        if ($totalWeek > $warningWeekly) {
            return [
                'rule' => 'fatigue_weekly',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => "Would exceed {$warningWeekly}h weekly warning threshold ({$this->fmt($totalWeek)}h total).",
            ];
        }

        return self::pass('fatigue_weekly');
    }

    /**
     * Min rest gap: the gap between this shift and the nearest adjacent shift must
     * meet the configured minimum rest hours.
     */
    protected function checkMinRestGap(User $user, CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $ignoreShiftId): array
    {
        $minRestHours = (float) config('hr.fatigue.min_rest_between_shifts_hours', 10);

        $query = Shift::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['cancelled'])
            ->when($ignoreShiftId, fn ($q) => $q->where('id', '!=', $ignoreShiftId));

        // Nearest shift ending before this one starts.
        $before = (clone $query)
            ->where('ends_at', '<=', $startsAt)
            ->orderByDesc('ends_at')
            ->first();

        if ($before) {
            $gapBefore = Carbon::parse($before->ends_at)->floatDiffInHours($startsAt);
            if ($gapBefore < $minRestHours) {
                return [
                    'rule' => 'fatigue_rest',
                    'passed' => false,
                    'severity' => 'block',
                    'overrideable' => false,
                    'message' => "Only {$this->fmt($gapBefore)}h rest before this shift (minimum {$minRestHours}h required).",
                ];
            }
        }

        // Nearest shift starting after this one ends.
        $after = (clone $query)
            ->where('starts_at', '>=', $endsAt)
            ->orderBy('starts_at')
            ->first();

        if ($after) {
            $gapAfter = $endsAt->floatDiffInHours(Carbon::parse($after->starts_at));
            if ($gapAfter < $minRestHours) {
                return [
                    'rule' => 'fatigue_rest',
                    'passed' => false,
                    'severity' => 'block',
                    'overrideable' => false,
                    'message' => "Only {$this->fmt($gapAfter)}h rest after this shift (minimum {$minRestHours}h required).",
                ];
            }
        }

        return self::pass('fatigue_rest');
    }

    /**
     * Consecutive days: count how many consecutive calendar days the user would have
     * a shift, including the current shift's date. Warn at the configured max.
     */
    protected function checkConsecutiveDays(User $user, CarbonInterface $shiftDate, ?int $ignoreShiftId): array
    {
        $maxConsecutive = (int) config('hr.fatigue.max_consecutive_days', 7);

        $baseDate = $shiftDate->copy()->startOfDay();

        // Walk backwards.
        $daysBefore = 0;
        $cursor = $baseDate->copy()->subDay();
        while ($daysBefore < $maxConsecutive + 1) {
            if (! $this->hasShiftOnDate($user->id, $cursor, $ignoreShiftId)) {
                break;
            }
            $daysBefore++;
            $cursor->subDay();
        }

        // Walk forwards.
        $daysAfter = 0;
        $cursor = $baseDate->copy()->addDay();
        while ($daysAfter < $maxConsecutive + 1) {
            if (! $this->hasShiftOnDate($user->id, $cursor, $ignoreShiftId)) {
                break;
            }
            $daysAfter++;
            $cursor->addDay();
        }

        $totalConsecutive = $daysBefore + 1 + $daysAfter; // +1 for the shift day itself

        if ($totalConsecutive >= $maxConsecutive) {
            return [
                'rule' => 'fatigue_consecutive',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => "Would be {$totalConsecutive} consecutive days worked (maximum {$maxConsecutive}).",
            ];
        }

        return self::pass('fatigue_consecutive');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Sum actual shift hours for a user within a time window.
     */
    protected function sumShiftHoursInWindow(int $userId, CarbonInterface $windowStart, CarbonInterface $windowEnd, ?int $ignoreShiftId): float
    {
        $shifts = Shift::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->when($ignoreShiftId, fn ($q) => $q->where('id', '!=', $ignoreShiftId))
            ->get(['starts_at', 'ends_at']);

        $total = 0.0;
        foreach ($shifts as $shift) {
            $start = Carbon::parse($shift->starts_at)->max($windowStart);
            $end = Carbon::parse($shift->ends_at)->min($windowEnd);
            $total += $start->floatDiffInHours($end);
        }

        return $total;
    }

    /**
     * Check if the user has at least one non-cancelled shift on a given calendar date.
     */
    protected function hasShiftOnDate(int $userId, CarbonInterface $date, ?int $ignoreShiftId): bool
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        return Shift::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->when($ignoreShiftId, fn ($q) => $q->where('id', '!=', $ignoreShiftId))
            ->exists();
    }

    /**
     * Get all distinct calendar dates a shift spans.
     *
     * @return CarbonInterface[]
     */
    protected function calendarDatesSpanned(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $dates = [];
        $cursor = $startsAt->copy()->startOfDay();
        $lastDay = $endsAt->copy()->startOfDay();

        while ($cursor->lte($lastDay)) {
            $dates[] = $cursor->copy();
            $cursor->addDay();
        }

        return $dates;
    }

    protected function resolveCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    /**
     * Format a float to 1 decimal place.
     */
    protected function fmt(float $hours): string
    {
        return number_format($hours, 1);
    }

    /**
     * @return array{rule: string, passed: true, severity: 'block', overrideable: false, message: null}
     */
    protected static function pass(string $rule): array
    {
        return [
            'rule' => $rule,
            'passed' => true,
            'severity' => 'block',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
