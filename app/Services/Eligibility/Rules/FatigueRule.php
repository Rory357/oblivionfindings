<?php

namespace App\Services\Eligibility\Rules;

use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\LocalWorkTimeSegmenter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
     * Request-scoped memo of each user's non-cancelled shifts (id, starts_at,
     * ends_at only), keyed by user_id. The fatigue sub-checks previously fired
     * ~15-20 Shift queries per (shift, user) pair. They now feed this in-memory
     * set through the shared worker-local segmenter, so each user is fetched at
     * most once for the whole batch and every sub-check uses the same calendar.
     *
     * FatigueRule is constructor-injected into ShiftStaffEligibilityService and
     * resolved per request (never a singleton), so this cache lives for one
     * eligibility-service instance and clears naturally per request. The set
     * keeps the established query filter (status NOT IN ['cancelled'] —
     * completed shifts ARE counted for fatigue). The day/week partition is now
     * deliberately worker-local rather than application-UTC.
     *
     * @var array<int, Collection<int, Shift>>
     */
    protected array $userShiftsMemo = [];

    public function __construct(private readonly LocalWorkTimeSegmenter $segments) {}

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

        $candidateDays = $this->segments->byDay($startsAt, $endsAt);
        $candidateWeeks = $this->segments->byWeek($startsAt, $endsAt);

        return [
            $this->checkDailyHours($user, $candidateDays, $shift->id),
            $this->checkWeeklyHours($user, $candidateWeeks, $shift->id),
            $this->checkMinRestGap($user, $startsAt, $endsAt, $shift->id),
            $this->checkConsecutiveDays($user, array_keys($candidateDays), $shift->id),
        ];
    }

    /**
     * Daily hours: total hours on each calendar day the shift spans must not exceed the max.
     */
    protected function checkDailyHours(User $user, array $candidateDays, ?int $ignoreShiftId): array
    {
        $maxDaily = (float) config('hr.fatigue.max_hours_per_day', 12);
        $existingDays = $this->existingHoursByDay($user->id, $ignoreShiftId);

        foreach ($candidateDays as $localDate => $candidateHours) {
            $totalDay = ($existingDays[$localDate] ?? 0.0) + $candidateHours;

            if ($totalDay > $maxDaily) {
                $date = Carbon::createFromFormat('!Y-m-d', $localDate, $this->segments->timezone());
                $dateLabel = $date instanceof CarbonInterface ? $date->format('D j M') : $localDate;

                return [
                    'rule' => 'fatigue_daily',
                    'passed' => false,
                    'severity' => 'block',
                    'overrideable' => false,
                    'message' => "Would exceed {$maxDaily}h daily maximum ({$this->fmt($totalDay)}h total on {$dateLabel}).",
                ];
            }
        }

        return self::pass('fatigue_daily');
    }

    /**
     * Weekly hours: total hours in the ISO week must not exceed max (block) / warning threshold.
     */
    protected function checkWeeklyHours(User $user, array $candidateWeeks, ?int $ignoreShiftId): array
    {
        $maxWeekly = (float) config('hr.fatigue.max_hours_per_week', 50);
        $warningWeekly = (float) config('hr.fatigue.warning_threshold_weekly', 40);
        $existingWeeks = $this->existingHoursByWeek($user->id, $ignoreShiftId);
        $totals = [];

        foreach ($candidateWeeks as $weekStart => $candidateHours) {
            $totals[$weekStart] = ($existingWeeks[$weekStart] ?? 0.0) + $candidateHours;
        }

        foreach ($totals as $weekStart => $totalWeek) {
            if ($totalWeek > $maxWeekly) {
                return [
                    'rule' => 'fatigue_weekly',
                    'passed' => false,
                    'severity' => 'block',
                    'overrideable' => false,
                    'message' => "Would exceed {$maxWeekly}h weekly maximum ({$this->fmt($totalWeek)}h total in the week starting {$weekStart}).",
                ];
            }
        }

        foreach ($totals as $weekStart => $totalWeek) {
            if ($totalWeek > $warningWeekly) {
                return [
                    'rule' => 'fatigue_weekly',
                    'passed' => false,
                    'severity' => 'warning',
                    'overrideable' => true,
                    'message' => "Would exceed {$warningWeekly}h weekly warning threshold ({$this->fmt($totalWeek)}h total in the week starting {$weekStart}).",
                ];
            }
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
    protected function checkConsecutiveDays(User $user, array $candidateDays, ?int $ignoreShiftId): array
    {
        $maxConsecutive = (int) config('hr.fatigue.max_consecutive_days', 7);
        $occupied = array_fill_keys(array_keys($this->existingHoursByDay($user->id, $ignoreShiftId)), true);
        foreach ($candidateDays as $candidateDay) {
            $occupied[$candidateDay] = true;
        }
        $totalConsecutive = 0;

        foreach ($candidateDays as $candidateDay) {
            $baseDate = Carbon::createFromFormat('!Y-m-d', $candidateDay, $this->segments->timezone());
            if (! $baseDate) {
                continue;
            }

            $run = 1;
            $cursor = $baseDate->copy()->subDay();
            while (isset($occupied[$cursor->toDateString()]) && $run < $maxConsecutive + 2) {
                $run++;
                $cursor->subDay();
            }
            $cursor = $baseDate->copy()->addDay();
            while (isset($occupied[$cursor->toDateString()]) && $run < $maxConsecutive + 2) {
                $run++;
                $cursor->addDay();
            }

            $totalConsecutive = max($totalConsecutive, $run);
        }

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

    /** @return array<string, float> */
    protected function existingHoursByDay(int $userId, ?int $ignoreShiftId): array
    {
        $totals = [];
        foreach ($this->userShiftsForFatigue($userId) as $shift) {
            if ($ignoreShiftId && (int) $shift->id === (int) $ignoreShiftId) {
                continue;
            }
            if (! $shift->starts_at || ! $shift->ends_at) {
                continue;
            }
            foreach ($this->segments->byDay($shift->starts_at, $shift->ends_at) as $key => $hours) {
                $totals[$key] = ($totals[$key] ?? 0.0) + $hours;
            }
        }

        return $totals;
    }

    /** @return array<string, float> */
    protected function existingHoursByWeek(int $userId, ?int $ignoreShiftId): array
    {
        $totals = [];
        foreach ($this->userShiftsForFatigue($userId) as $shift) {
            if ($ignoreShiftId && (int) $shift->id === (int) $ignoreShiftId) {
                continue;
            }
            if (! $shift->starts_at || ! $shift->ends_at) {
                continue;
            }
            foreach ($this->segments->byWeek($shift->starts_at, $shift->ends_at) as $key => $hours) {
                $totals[$key] = ($totals[$key] ?? 0.0) + $hours;
            }
        }

        return $totals;
    }

    /**
     * Load (once per request) and memoize the user's non-cancelled shifts used by
     * the fatigue calculations. Only id/starts_at/ends_at are needed; rows are
     * tiny so the full set is safe to hold in memory for the batch.
     *
     * @return Collection<int, Shift>
     */
    protected function userShiftsForFatigue(int $userId): Collection
    {
        if (array_key_exists($userId, $this->userShiftsMemo)) {
            return $this->userShiftsMemo[$userId];
        }

        return $this->userShiftsMemo[$userId] = Shift::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('starts_at')
            ->get(['id', 'starts_at', 'ends_at']);
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
