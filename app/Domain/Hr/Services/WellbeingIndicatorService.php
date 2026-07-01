<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Models\HrWellbeingFlagAction;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WellbeingIndicatorService
{
    /**
     * Flag level thresholds.
     */
    public const FLAG_RULES = [
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

    public function calculateIndicators(User $user, Carbon $periodStart, Carbon $periodEnd): HrWellbeingIndicator
    {
        $timesheets = Timesheet::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('work_date')
            ->get();

        $overtimeHours = $this->calculateOvertime($user, $timesheets, $periodStart, $periodEnd);
        $consecutiveDays = $this->calculateConsecutiveDays($timesheets);
        $sickLeave30d = $this->countSickLeaveDays($user->id, 30, $periodEnd);
        $sickLeave90d = $this->countSickLeaveDays($user->id, 90, $periodEnd);
        $shiftsWorked7d = $this->countShiftsInLastDays($user->id, 7, $periodEnd);
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
                'tenant_id' => $user->tenant_id ?? null,
                'user_id' => $user->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                ...$metrics,
                'flag_level' => $flagLevel,
                'calculated_at' => now(),
            ]
        );
    }

    public function calculateAllIndicators(?int $tenantId, Carbon $periodStart, Carbon $periodEnd): int
    {
        $profiles = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->with('user:id,name,email')
            ->get();

        $count = 0;
        foreach ($profiles as $profile) {
            if (! $profile->user) {
                continue;
            }

            try {
                $this->calculateIndicators($profile->user, $periodStart, $periodEnd);
                $count++;
            } catch (\Throwable $exception) {
                Log::warning('Wellbeing calculation failed', [
                    'user_id' => $profile->user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function getFlaggedStaff(?int $tenantId, ?string $flagLevel = null): Collection
    {
        $query = HrWellbeingIndicator::query()
            ->where('flag_level', '!=', 'none')
            ->when($tenantId !== null, fn ($builder) => $builder->where('tenant_id', $tenantId))
            ->whereIn('id', function ($sub) use ($tenantId) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('hr_wellbeing_indicators')
                    ->when($tenantId !== null, fn ($inner) => $inner->where('tenant_id', $tenantId))
                    ->groupBy('user_id');
            })
            ->with(['user.hrEmployeeProfile.primarySite:id,name']);

        if ($flagLevel) {
            $query->where('flag_level', $flagLevel);
        }

        $indicators = $query
            ->orderByRaw("CASE WHEN flag_level = 'red' THEN 0 ELSE 1 END")
            ->get();

        $userIds = $indicators->pluck('user_id')->filter()->unique()->values();
        $latestActions = $this->latestFlagActionsFor($userIds);
        $lastCheckins = $this->lastCheckinDatesFor($tenantId, $userIds);
        $openPlanCounts = $this->openPlanCountsFor($tenantId, $userIds);
        $today = now()->startOfDay();

        return $indicators
            ->map(function (HrWellbeingIndicator $indicator) use ($latestActions, $lastCheckins, $openPlanCounts, $today) {
                $action = $latestActions->get($indicator->user_id);

                // Hide dismissed flags and flags snoozed to a future date.
                if ($action) {
                    if ($action->action === 'dismiss') {
                        return null;
                    }
                    if ($action->action === 'snooze' && $action->snooze_until && $action->snooze_until->startOfDay()->greaterThan($today)) {
                        return null;
                    }
                }

                $lastCheckinAt = $lastCheckins->get($indicator->user_id);

                return [
                    'user_id' => $indicator->user_id,
                    'name' => $indicator->user?->name,
                    'position_title' => $indicator->user?->hrEmployeeProfile?->position_title,
                    'site_name' => $indicator->user?->hrEmployeeProfile?->primarySite?->name,
                    'flag_level' => $indicator->flag_level,
                    'period_start' => $indicator->period_start?->toDateString(),
                    'period_end' => $indicator->period_end?->toDateString(),
                    'calculated_at' => $indicator->calculated_at?->toIso8601String(),
                    'triggered_rules' => $this->getTriggeredRules($indicator),
                    'metrics' => [
                        'overtime_hours' => (float) $indicator->overtime_hours,
                        'consecutive_days_worked' => (int) $indicator->consecutive_days_worked,
                        'sick_leave_days_30d' => (int) $indicator->sick_leave_days_30d,
                        'sick_leave_days_90d' => (int) $indicator->sick_leave_days_90d,
                        'shifts_worked_7d' => (int) $indicator->shifts_worked_7d,
                        'average_shift_length_hours' => (float) $indicator->average_shift_length_hours,
                    ],
                    'last_checkin_at' => $lastCheckinAt?->toIso8601String(),
                    'open_plan_count' => (int) ($openPlanCounts->get($indicator->user_id) ?? 0),
                    'latest_action' => $action ? [
                        'action' => $action->action,
                        'actor_name' => $action->actor?->name,
                        'snooze_until' => $action->snooze_until?->toDateString(),
                        'reason' => $action->reason,
                        'created_at' => $action->created_at?->toIso8601String(),
                    ] : null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Latest triage action keyed by staff_user_id.
     *
     * @return Collection<int, HrWellbeingFlagAction>
     */
    protected function latestFlagActionsFor(Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('hr_wellbeing_flag_actions')) {
            return collect();
        }

        return HrWellbeingFlagAction::query()
            ->whereIn('staff_user_id', $userIds)
            ->whereIn('id', function ($sub) use ($userIds) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('hr_wellbeing_flag_actions')
                    ->whereIn('staff_user_id', $userIds->all())
                    ->groupBy('staff_user_id');
            })
            ->with('actor:id,name')
            ->get()
            ->keyBy('staff_user_id');
    }

    /**
     * @return Collection<int, Carbon>
     */
    protected function lastCheckinDatesFor(?int $tenantId, Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('hr_wellbeing_checkins')) {
            return collect();
        }

        return HrWellbeingCheckin::query()
            ->whereIn('staff_user_id', $userIds)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->selectRaw('staff_user_id, MAX(created_at) as last_at')
            ->groupBy('staff_user_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->staff_user_id => Carbon::parse($row->last_at)]);
    }

    /**
     * @return Collection<int, int>
     */
    protected function openPlanCountsFor(?int $tenantId, Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasColumn('hr_engagement_action_plans', 'staff_user_id')) {
            return collect();
        }

        return HrEngagementActionPlan::query()
            ->whereIn('staff_user_id', $userIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->selectRaw('staff_user_id, COUNT(*) as plan_count')
            ->groupBy('staff_user_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->staff_user_id => (int) $row->plan_count]);
    }

    /**
     * Per-staff indicator history for the Signals sparkline (oldest → newest).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStaffTrend(?int $tenantId, int $userId, int $limit = 12): array
    {
        return HrWellbeingIndicator::query()
            ->where('user_id', $userId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('period_end')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (HrWellbeingIndicator $indicator) => [
                'period_end' => $indicator->period_end?->toDateString(),
                'flag_level' => $indicator->flag_level,
                'overtime_hours' => (float) $indicator->overtime_hours,
                'consecutive_days_worked' => (int) $indicator->consecutive_days_worked,
                'average_shift_length_hours' => (float) $indicator->average_shift_length_hours,
            ])
            ->all();
    }

    /**
     * Tenant-wide red/amber counts over recent periods (oldest → newest) for the
     * Overview trend.
     *
     * @return array<int, array{period_end: string|null, red: int, amber: int, total: int}>
     */
    public function getTenantTrend(?int $tenantId, int $points = 8): array
    {
        $rows = HrWellbeingIndicator::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->selectRaw("period_end, "
                . "SUM(CASE WHEN flag_level = 'red' THEN 1 ELSE 0 END) as red, "
                . "SUM(CASE WHEN flag_level = 'amber' THEN 1 ELSE 0 END) as amber, "
                . "COUNT(*) as total")
            ->groupBy('period_end')
            ->orderByDesc('period_end')
            ->limit($points)
            ->get()
            ->reverse()
            ->values();

        return $rows->map(fn ($row) => [
            'period_end' => $row->period_end ? Carbon::parse($row->period_end)->toDateString() : null,
            'red' => (int) $row->red,
            'amber' => (int) $row->amber,
            'total' => (int) $row->total,
        ])->all();
    }

    /**
     * @return array{total_staff: int, flagged_red: int, flagged_amber: int, healthy: int}
     */
    public function getSummary(?int $tenantId): array
    {
        $latest = HrWellbeingIndicator::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereIn('id', function ($sub) use ($tenantId) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('hr_wellbeing_indicators')
                    ->when($tenantId !== null, fn ($inner) => $inner->where('tenant_id', $tenantId))
                    ->groupBy('user_id');
            });

        return [
            'total_staff' => (clone $latest)->count(),
            'flagged_red' => (clone $latest)->where('flag_level', 'red')->count(),
            'flagged_amber' => (clone $latest)->where('flag_level', 'amber')->count(),
            'healthy' => (clone $latest)->where('flag_level', 'none')->count(),
        ];
    }

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

            if (! $matches) {
                continue;
            }

            if ($rule['flag'] === 'red') {
                return 'red';
            }

            if ($rule['flag'] === 'amber' && $flagLevel !== 'red') {
                $flagLevel = 'amber';
            }
        }

        return $flagLevel;
    }

    protected function getTriggeredRules(HrWellbeingIndicator $indicator): array
    {
        $metrics = [
            'overtime_hours' => (float) $indicator->overtime_hours,
            'consecutive_days_worked' => (int) $indicator->consecutive_days_worked,
            'sick_leave_days_30d' => (int) $indicator->sick_leave_days_30d,
            'sick_leave_days_90d' => (int) $indicator->sick_leave_days_90d,
            'shifts_worked_7d' => (int) $indicator->shifts_worked_7d,
            'average_shift_length_hours' => (float) $indicator->average_shift_length_hours,
        ];

        $triggered = [];
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
                $triggered[] = $rule['label'];
            }
        }

        return $triggered;
    }

    protected function calculateOvertime(User $user, Collection $timesheets, Carbon $periodStart, Carbon $periodEnd): float
    {
        $contractedWeekly = (float) ($user->hrEmployeeProfile?->hours_per_week ?? 40);
        $totalHours = $timesheets->sum(fn (Timesheet $timesheet) => $timesheet->total_hours);

        $daysInPeriod = max(1, $periodStart->copy()->startOfDay()->diffInDays($periodEnd->copy()->endOfDay()) + 1);
        $weeksInPeriod = $daysInPeriod / 7;
        $expectedHours = $contractedWeekly * $weeksInPeriod;

        return max(0, round($totalHours - $expectedHours, 2));
    }

    protected function calculateConsecutiveDays(Collection $timesheets): int
    {
        $dates = $timesheets->pluck('work_date')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return 0;
        }

        $maxStreak = 1;
        $currentStreak = 1;

        for ($index = 1; $index < $dates->count(); $index++) {
            $previousDate = Carbon::parse($dates[$index - 1]);
            $currentDate = Carbon::parse($dates[$index]);

            if ($previousDate->diffInDays($currentDate) === 1) {
                $currentStreak++;
                $maxStreak = max($maxStreak, $currentStreak);
            } else {
                $currentStreak = 1;
            }
        }

        return $maxStreak;
    }

    protected function countSickLeaveDays(int $userId, int $days, Carbon $periodEnd): int
    {
        $windowStart = $periodEnd->copy()->subDays($days)->startOfDay();

        $requests = HrLeaveRequest::query()
            ->where('user_id', $userId)
            ->where('leave_type', 'sick')
            ->where('status', 'approved')
            ->where('starts_at', '<=', $periodEnd)
            ->where('ends_at', '>=', $windowStart)
            ->get();

        $totalDays = 0;
        foreach ($requests as $request) {
            $start = Carbon::parse($request->starts_at)->max($windowStart);
            $end = Carbon::parse($request->ends_at)->min($periodEnd);
            $day = $start->copy()->startOfDay();

            while ($day->lessThanOrEqualTo($end)) {
                if (! $day->isWeekend()) {
                    $totalDays++;
                }
                $day->addDay();
            }
        }

        return $totalDays;
    }

    protected function countShiftsInLastDays(int $userId, int $days, Carbon $periodEnd): int
    {
        $windowStart = $periodEnd->copy()->subDays($days)->toDateString();

        return Timesheet::query()
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$windowStart, $periodEnd->toDateString()])
            ->distinct('work_date')
            ->count('work_date');
    }

    protected function calculateAverageShiftLength(Collection $timesheets): float
    {
        if ($timesheets->isEmpty()) {
            return 0;
        }

        $totalHours = $timesheets->sum(fn (Timesheet $timesheet) => $timesheet->total_hours);
        return round($totalHours / max($timesheets->count(), 1), 2);
    }
}

