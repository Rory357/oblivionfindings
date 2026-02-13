<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WellbeingIndicatorService
{
    /**
     * Flag level thresholds.
     *
     * Each rule defines a metric, operator, threshold, and resulting flag level.
     * Flag levels: none, amber (warning), red (critical).
     */
    public const FLAG_RULES = [
        // Red flags - immediate attention required
        [
            'metric' => 'consecutive_days_worked',
            'operator' => '>=',
            'threshold' => 12,
            'flag' => 'red',
            'label' => 'Worked 12+ consecutive days without a break',
        ],
        [
            'metric' => 'overtime_hours',
            'operator' => '>=',
            'threshold' => 20,
            'flag' => 'red',
            'label' => 'Excessive overtime (20+ hours in period)',
        ],
        [
            'metric' => 'sick_leave_days_90d',
            'operator' => '>=',
            'threshold' => 10,
            'flag' => 'red',
            'label' => 'High sick leave usage (10+ days in 90 days)',
        ],
        [
            'metric' => 'average_shift_length_hours',
            'operator' => '>=',
            'threshold' => 12,
            'flag' => 'red',
            'label' => 'Average shift length exceeds 12 hours',
        ],

        // Amber flags - monitoring required
        [
            'metric' => 'consecutive_days_worked',
            'operator' => '>=',
            'threshold' => 7,
            'flag' => 'amber',
            'label' => 'Worked 7+ consecutive days without a break',
        ],
        [
            'metric' => 'overtime_hours',
            'operator' => '>=',
            'threshold' => 10,
            'flag' => 'amber',
            'label' => 'Elevated overtime (10+ hours in period)',
        ],
        [
            'metric' => 'sick_leave_days_30d',
            'operator' => '>=',
            'threshold' => 3,
            'flag' => 'amber',
            'label' => 'Frequent sick leave (3+ days in 30 days)',
        ],
        [
            'metric' => 'shifts_worked_7d',
            'operator' => '>=',
            'threshold' => 7,
            'flag' => 'amber',
            'label' => 'No rest day in the past 7 days',
        ],
        [
            'metric' => 'average_shift_length_hours',
            'operator' => '>=',
            'threshold' => 10,
            'flag' => 'amber',
            'label' => 'Average shift length exceeds 10 hours',
        ],
    ];

    /**
     * Calculate wellbeing indicators for a single employee.
     *
     * Evaluates timesheet and leave data to compute fatigue, overwork,
     * and wellbeing metrics. Applies FLAG_RULES to determine the flag level.
     *
     * @param  User    $user
     * @param  Carbon  $periodStart
     * @param  Carbon  $periodEnd
     * @return HrWellbeingIndicator
     */
    public function calculateIndicators(User $user, Carbon $periodStart, Carbon $periodEnd): HrWellbeingIndicator
    {
        // TODO: Query timesheets for the user within the period
        // TODO: Calculate overtime_hours (hours beyond contracted hours_per_week)
        // TODO: Calculate consecutive_days_worked (longest streak with no rest day)
        // TODO: Count sick_leave_days_30d and sick_leave_days_90d from HrLeaveRequest
        // TODO: Count shifts_worked_7d (rolling 7-day window ending at period_end)
        // TODO: Calculate average_shift_length_hours from timesheet durations
        // TODO: Apply FLAG_RULES to determine flag_level (highest matching flag wins)
        // TODO: Create or update HrWellbeingIndicator record
        // TODO: If flag_level is 'red', fire WellbeingAlert event for immediate notification
        // TODO: Log audit trail entry

        $timesheets = Timesheet::where('user_id', $user->id)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->where('status', 'approved')
            ->orderBy('date')
            ->get();

        $overtimeHours = $this->calculateOvertime($user, $timesheets);
        $consecutiveDays = $this->calculateConsecutiveDays($timesheets);
        $sickLeave30d = $this->countSickLeaveDays($user->id, 30);
        $sickLeave90d = $this->countSickLeaveDays($user->id, 90);
        $shiftsWorked7d = $this->countShiftsInLastDays($user->id, 7);
        $avgShiftLength = $this->calculateAverageShiftLength($timesheets);

        $metrics = [
            'overtime_hours' => $overtimeHours,
            'consecutive_days_worked' => $consecutiveDays,
            'sick_leave_days_30d' => $sickLeave30d,
            'sick_leave_days_90d' => $sickLeave90d,
            'shifts_worked_7d' => $shiftsWorked7d,
            'average_shift_length_hours' => $avgShiftLength,
        ];

        $flagLevel = $this->determineFlagLevel($metrics);

        return HrWellbeingIndicator::updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                ...$metrics,
                'flag_level' => $flagLevel,
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * Calculate wellbeing indicators for all active employees in a tenant.
     *
     * @param  int     $tenantId
     * @param  Carbon  $periodStart
     * @param  Carbon  $periodEnd
     * @return int  Number of employees evaluated
     */
    public function calculateAllIndicators(?int $tenantId, Carbon $periodStart, Carbon $periodEnd): int
    {
        // TODO: Query all active employees for the tenant
        // TODO: For each, call calculateIndicators()
        // TODO: Return count of employees processed

        $employees = User::staff()
            ->where('tenant_id', $tenantId)
            ->whereHas('staffProfile', fn($q) => $q->where('is_active', true))
            ->get();

        $count = 0;
        foreach ($employees as $user) {
            try {
                $this->calculateIndicators($user, $periodStart, $periodEnd);
                $count++;
            } catch (\Throwable $e) {
                Log::warning("Wellbeing calculation failed for user {$user->id}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    /**
     * Get all flagged staff members for a tenant.
     *
     * Returns employees whose most recent wellbeing indicator has an
     * amber or red flag, ordered by severity (red first).
     *
     * @param  int          $tenantId
     * @param  string|null  $flagLevel  Filter to a specific flag level ('amber' or 'red'), or null for all flagged
     * @return Collection
     */
    public function getFlaggedStaff(?int $tenantId, ?string $flagLevel = null): Collection
    {
        // TODO: Query the most recent HrWellbeingIndicator per user for the tenant
        // TODO: Filter to flag_level != 'none'
        // TODO: Optionally filter to specific flag_level
        // TODO: Order by flag_level (red first, then amber)
        // TODO: Include user and profile relationships
        // TODO: Map to a summary array with employee name, flag_level, triggered rules

        $query = HrWellbeingIndicator::where('tenant_id', $tenantId)
            ->where('flag_level', '!=', 'none')
            ->whereIn('id', function ($sub) use ($tenantId) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('hr_wellbeing_indicators')
                    ->where('tenant_id', $tenantId)
                    ->groupBy('user_id');
            })
            ->with(['user.staffProfile']);

        if ($flagLevel) {
            $query->where('flag_level', $flagLevel);
        }

        return $query
            ->orderByRaw("CASE WHEN flag_level = 'red' THEN 0 ELSE 1 END")
            ->get()
            ->map(function (HrWellbeingIndicator $indicator) {
                $triggeredRules = $this->getTriggeredRules($indicator);

                return [
                    'user_id' => $indicator->user_id,
                    'name' => $indicator->user?->name,
                    'position_title' => $indicator->user?->staffProfile?->position_title,
                    'flag_level' => $indicator->flag_level,
                    'period_start' => $indicator->period_start?->toDateString(),
                    'period_end' => $indicator->period_end?->toDateString(),
                    'calculated_at' => $indicator->calculated_at?->toIso8601String(),
                    'triggered_rules' => $triggeredRules,
                    'metrics' => [
                        'overtime_hours' => $indicator->overtime_hours,
                        'consecutive_days_worked' => $indicator->consecutive_days_worked,
                        'sick_leave_days_30d' => $indicator->sick_leave_days_30d,
                        'sick_leave_days_90d' => $indicator->sick_leave_days_90d,
                        'shifts_worked_7d' => $indicator->shifts_worked_7d,
                        'average_shift_length_hours' => $indicator->average_shift_length_hours,
                    ],
                ];
            });
    }

    /**
     * Get a wellbeing summary for a tenant (dashboard widget data).
     *
     * @param  int  $tenantId
     * @return array{total_staff: int, flagged_red: int, flagged_amber: int, healthy: int}
     */
    public function getSummary(?int $tenantId): array
    {
        // TODO: Count latest indicators by flag_level for the tenant
        // TODO: Return summary counts

        $latest = HrWellbeingIndicator::where('tenant_id', $tenantId)
            ->whereIn('id', function ($sub) use ($tenantId) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('hr_wellbeing_indicators')
                    ->where('tenant_id', $tenantId)
                    ->groupBy('user_id');
            });

        return [
            'total_staff' => (clone $latest)->count(),
            'flagged_red' => (clone $latest)->where('flag_level', 'red')->count(),
            'flagged_amber' => (clone $latest)->where('flag_level', 'amber')->count(),
            'healthy' => (clone $latest)->where('flag_level', 'none')->count(),
        ];
    }

    /**
     * Determine the highest flag level from the metrics using FLAG_RULES.
     */
    protected function determineFlagLevel(array $metrics): string
    {
        $flagLevel = 'none';

        foreach (self::FLAG_RULES as $rule) {
            $value = $metrics[$rule['metric']] ?? 0;
            $matches = match ($rule['operator']) {
                '>=' => $value >= $rule['threshold'],
                '>' => $value > $rule['threshold'],
                '<=' => $value <= $rule['threshold'],
                '<' => $value < $rule['threshold'],
                '==' => $value == $rule['threshold'],
                default => false,
            };

            if ($matches) {
                if ($rule['flag'] === 'red') {
                    return 'red';
                }
                if ($rule['flag'] === 'amber' && $flagLevel !== 'red') {
                    $flagLevel = 'amber';
                }
            }
        }

        return $flagLevel;
    }

    /**
     * Get the list of rules triggered by a wellbeing indicator.
     */
    protected function getTriggeredRules(HrWellbeingIndicator $indicator): array
    {
        $metrics = [
            'overtime_hours' => $indicator->overtime_hours,
            'consecutive_days_worked' => $indicator->consecutive_days_worked,
            'sick_leave_days_30d' => $indicator->sick_leave_days_30d,
            'sick_leave_days_90d' => $indicator->sick_leave_days_90d,
            'shifts_worked_7d' => $indicator->shifts_worked_7d,
            'average_shift_length_hours' => $indicator->average_shift_length_hours,
        ];

        $triggered = [];
        foreach (self::FLAG_RULES as $rule) {
            $value = $metrics[$rule['metric']] ?? 0;
            $matches = match ($rule['operator']) {
                '>=' => $value >= $rule['threshold'],
                '>' => $value > $rule['threshold'],
                default => false,
            };
            if ($matches) {
                $triggered[] = $rule['label'];
            }
        }

        return $triggered;
    }

    /**
     * Calculate overtime hours from timesheets vs contracted hours.
     */
    protected function calculateOvertime(User $user, Collection $timesheets): float
    {
        // TODO: Look up contracted hours_per_week from HrEmployeeProfile
        // TODO: Sum all timesheet hours in the period
        // TODO: Calculate weeks in period and determine expected hours
        // TODO: Overtime = actual - expected (if positive)

        $profile = $user->staffProfile;
        $contractedWeekly = $profile ? (float) $profile->hours_per_week : 40;

        $totalHours = $timesheets->sum('total_hours');
        $weeks = max(1, $timesheets->pluck('date')->unique()->count() / 7);
        $expectedHours = $contractedWeekly * $weeks;

        return max(0, round($totalHours - $expectedHours, 2));
    }

    /**
     * Calculate the longest streak of consecutive days worked.
     */
    protected function calculateConsecutiveDays(Collection $timesheets): int
    {
        // TODO: Get unique dates from timesheets, sorted ascending
        // TODO: Walk through dates and count consecutive calendar days
        // TODO: Return the longest streak

        $dates = $timesheets->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return 0;
        }

        $maxStreak = 1;
        $currentStreak = 1;

        for ($i = 1; $i < $dates->count(); $i++) {
            $prev = Carbon::parse($dates[$i - 1]);
            $curr = Carbon::parse($dates[$i]);

            if ($prev->diffInDays($curr) === 1) {
                $currentStreak++;
                $maxStreak = max($maxStreak, $currentStreak);
            } else {
                $currentStreak = 1;
            }
        }

        return $maxStreak;
    }

    /**
     * Count sick leave days in the last N days.
     */
    protected function countSickLeaveDays(int $userId, int $days): int
    {
        // TODO: Query HrLeaveRequest for approved sick leave in the window
        // TODO: Sum the business days between starts_at and ends_at for each request

        return HrLeaveRequest::where('user_id', $userId)
            ->where('leave_type', 'sick')
            ->where('status', 'approved')
            ->where('starts_at', '>=', now()->subDays($days))
            ->count();
    }

    /**
     * Count shifts worked in the last N days.
     */
    protected function countShiftsInLastDays(int $userId, int $days): int
    {
        // TODO: Query approved timesheets in the rolling window
        // TODO: Count unique dates

        return Timesheet::where('user_id', $userId)
            ->where('status', 'approved')
            ->where('date', '>=', now()->subDays($days))
            ->distinct('date')
            ->count('date');
    }

    /**
     * Calculate the average shift length in hours.
     */
    protected function calculateAverageShiftLength(Collection $timesheets): float
    {
        // TODO: Calculate average of total_hours across all timesheets
        // TODO: Return 0 if no timesheets

        if ($timesheets->isEmpty()) {
            return 0;
        }

        return round($timesheets->avg('total_hours'), 2);
    }
}
