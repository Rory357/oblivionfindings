<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Models\Timesheet;
use App\Services\Payroll\ShiftRateSegmenter;

class PayrollRateResolver
{
    public function __construct(
        protected ShiftRateSegmenter $segmenter = new ShiftRateSegmenter(),
    ) {
    }

    /**
     * @return array{pay_type: string, pay_rate: float, payroll_cost: float, segments: array|null, dominant_type: string|null}
     */
    public function resolve(Timesheet $timesheet): array
    {
        $timesheet->loadMissing([
            'user.hrEmployeeProfile',
            'shift:id,site_id,service_context_id',
            'client:id,service_context_id',
        ]);

        $profile = $timesheet->user?->hrEmployeeProfile;
        $rule = $this->resolvePayRateRule($profile, $timesheet);
        $baseRate = (float) ($profile?->hourly_rate ?? 0);
        $hours = max((float) $timesheet->total_hours, 0);
        $payType = $this->mapPayType($timesheet);
        $rates = $this->resolveRateInputs($rule);

        // ── Single-rate types: sleepover, on_call, public_holiday, weekend ──
        // These override time-of-day bands entirely.
        if (in_array($payType, ['sleepover', 'on_call', 'public_holiday', 'weekend'], true)) {
            $payRate = match ($payType) {
                'sleepover' => (float) $rates['sleepover_rate'],
                'on_call' => (float) $rates['on_call_rate'],
                'public_holiday' => round($baseRate * $rates['public_holiday_multiplier'], 2),
                'weekend' => round($baseRate * max($rates['regular_multiplier'], 1), 2),
            };

            $payrollCost = match ($payType) {
                'sleepover' => (float) $rates['sleepover_rate'],
                'on_call' => round($hours * $rates['on_call_rate'], 2),
                'public_holiday' => round($hours * $baseRate * $rates['public_holiday_multiplier'], 2),
                'weekend' => round($hours * $baseRate * max($rates['regular_multiplier'], 1), 2),
            };

            return [
                'pay_type' => $payType,
                'pay_rate' => $payRate,
                'payroll_cost' => $payrollCost,
                'segments' => null,
                'dominant_type' => null,
            ];
        }

        // ── Time-of-day segmentation: standard / evening / night ──
        $segments = $this->resolveSegments($timesheet);

        if ($segments === null || count($segments) <= 1) {
            // Single band — use the existing simple path.
            $singleType = $segments[0]['type'] ?? $payType;
            $payRate = $this->rateForBand($singleType, $baseRate, $rates);
            $payrollCost = round($hours * $payRate, 2);

            return [
                'pay_type' => $singleType,
                'pay_rate' => $payRate,
                'payroll_cost' => $payrollCost,
                'segments' => null,
                'dominant_type' => null,
            ];
        }

        // ── Mixed: shift spans multiple time-of-day bands ──
        $breakMinutes = max((int) ($timesheet->break_minutes ?? 0), 0);
        $totalRawMinutes = array_sum(array_column($segments, 'minutes'));
        $segmentsWithRates = [];
        $totalCost = 0.0;
        $breakRemaining = $breakMinutes;

        foreach ($segments as $seg) {
            $rate = $this->rateForBand($seg['type'], $baseRate, $rates);

            // Distribute break minutes proportionally across segments.
            $segBreak = $totalRawMinutes > 0
                ? (int) round($breakMinutes * ($seg['minutes'] / $totalRawMinutes))
                : 0;
            // Guard: don't allocate more break than remains.
            $segBreak = min($segBreak, $breakRemaining);
            $breakRemaining -= $segBreak;

            $payableMinutes = max($seg['minutes'] - $segBreak, 0);
            $segHours = round($payableMinutes / 60, 4);
            $segCost = round($segHours * $rate, 2);
            $totalCost += $segCost;

            $segmentsWithRates[] = [
                'type' => $seg['type'],
                'minutes' => $seg['minutes'],
                'break_minutes' => $segBreak,
                'payable_minutes' => $payableMinutes,
                'rate' => $rate,
                'cost' => $segCost,
            ];
        }

        $dominantType = $segments[0]['type']; // already sorted by minutes desc

        // Weighted average rate for backwards-compatible pay_rate field.
        $totalPayableMinutes = array_sum(array_column($segmentsWithRates, 'payable_minutes'));
        $weightedRate = $totalPayableMinutes > 0
            ? round($totalCost / ($totalPayableMinutes / 60), 2)
            : 0.0;

        return [
            'pay_type' => 'mixed',
            'pay_rate' => $weightedRate,
            'payroll_cost' => round($totalCost, 2),
            'segments' => $segmentsWithRates,
            'dominant_type' => $dominantType,
        ];
    }

    /**
     * Determine the primary pay classification for a timesheet.
     *
     * Special types (sleepover, on_call, public_holiday, weekend) take precedence
     * over time-of-day bands. For time-of-day classification, this returns the
     * dominant band when multiple apply, or 'mixed' when segmented costing is used.
     */
    public function mapPayType(Timesheet $timesheet): string
    {
        if ($timesheet->sleepover) {
            return 'sleepover';
        }

        if ($timesheet->on_call) {
            return 'on_call';
        }

        if ($timesheet->public_holiday) {
            return 'public_holiday';
        }

        $dayOfWeek = $timesheet->work_date?->dayOfWeek;

        if ($dayOfWeek !== null && in_array($dayOfWeek, [6, 0], true)) {
            return 'weekend';
        }

        // For time-of-day classification, use the segmenter if we have start/end times.
        $startsAt = $timesheet->starts_at;
        $endsAt = $timesheet->ends_at;

        if ($startsAt && $endsAt) {
            return $this->segmenter->dominantType($startsAt, $endsAt);
        }

        return 'standard';
    }

    /**
     * Get the rate ($/hour) for a specific time-of-day band.
     */
    public function rateForBand(string $band, float $baseRate, array $rates): float
    {
        return match ($band) {
            'night' => round($baseRate * max($rates['regular_multiplier'], 1), 2),
            'evening' => round($baseRate * max($rates['regular_multiplier'], 1), 2),
            default => round($baseRate * $rates['regular_multiplier'], 2), // standard
        };
    }

    /**
     * Build rate segments from the timesheet's time window.
     *
     * @return array<int, array{type: string, minutes: int}>|null
     */
    protected function resolveSegments(Timesheet $timesheet): ?array
    {
        $startsAt = $timesheet->starts_at;
        $endsAt = $timesheet->ends_at;

        if (! $startsAt || ! $endsAt) {
            return null;
        }

        $segments = $this->segmenter->segment($startsAt, $endsAt);

        return count($segments) > 0 ? $segments : null;
    }

    protected function resolvePayRateRule(?HrEmployeeProfile $profile, Timesheet $timesheet): ?HrPayRateRule
    {
        $positionRole = $profile?->position_role ?? $timesheet->user?->role;
        $siteId = $timesheet->shift_site_id ?: $timesheet->shift?->site_id ?: $profile?->primary_site_id;
        $serviceContextId = $timesheet->shift_service_context_id ?: $timesheet->shift?->service_context_id ?: $timesheet->client?->service_context_id;
        $tenantId = $profile?->tenant_id ?? $timesheet->user?->tenant_id;

        return HrPayRateRule::query()
            ->active()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(function ($query) use ($positionRole) {
                $query->whereNull('position_role');
                if ($positionRole) {
                    $query->orWhere('position_role', $positionRole);
                }
            })
            ->where(function ($query) use ($siteId) {
                $query->whereNull('site_id');
                if ($siteId) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->where(function ($query) use ($serviceContextId) {
                $query->whereNull('service_context_id');
                if ($serviceContextId) {
                    $query->orWhere('service_context_id', $serviceContextId);
                }
            })
            ->where(function ($query) use ($timesheet) {
                $query->whereNull('applies_on_public_holiday')
                    ->orWhere('applies_on_public_holiday', (bool) ($timesheet->public_holiday ?? false));
            })
            ->where(function ($query) use ($timesheet) {
                $query->whereNull('applies_on_sleepover')
                    ->orWhere('applies_on_sleepover', (bool) ($timesheet->sleepover ?? false));
            })
            ->where(function ($query) use ($timesheet) {
                $query->whereNull('applies_on_call')
                    ->orWhere('applies_on_call', (bool) ($timesheet->on_call ?? false));
            })
            ->where(function ($query) use ($timesheet) {
                $query->whereNull('effective_from');
                if ($timesheet->work_date) {
                    $query->orWhere('effective_from', '<=', $timesheet->work_date);
                }
            })
            ->where(function ($query) use ($timesheet) {
                $query->whereNull('effective_to');
                if ($timesheet->work_date) {
                    $query->orWhere('effective_to', '>=', $timesheet->work_date);
                }
            })
            ->orderBy('priority')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{regular_multiplier: float, public_holiday_multiplier: float, sleepover_rate: float, on_call_rate: float}
     */
    protected function resolveRateInputs(?HrPayRateRule $rule): array
    {
        return [
            'regular_multiplier' => (float) ($rule?->regular_multiplier ?? config('hr.payroll.default_regular_multiplier', 1.00)),
            'public_holiday_multiplier' => (float) ($rule?->public_holiday_multiplier ?? config('hr.payroll.default_public_holiday_multiplier', 1.50)),
            'sleepover_rate' => (float) ($rule?->sleepover_flat_rate ?? config('hr.payroll.default_sleepover_flat_rate', 0)),
            'on_call_rate' => (float) ($rule?->on_call_hourly_rate ?? config('hr.payroll.default_on_call_hourly_rate', 0)),
        ];
    }
}
