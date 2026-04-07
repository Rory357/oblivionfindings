<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Models\Timesheet;

class PayrollRateResolver
{
    /**
     * @return array{pay_type: string, pay_rate: float, payroll_cost: float}
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

        $payRate = match ($payType) {
            'sleepover' => (float) $rates['sleepover_rate'],
            'on_call' => (float) $rates['on_call_rate'],
            'public_holiday' => round($baseRate * $rates['public_holiday_multiplier'], 2),
            'weekend', 'night', 'evening' => round($baseRate * max($rates['regular_multiplier'], 1), 2),
            default => round($baseRate * $rates['regular_multiplier'], 2),
        };

        $payrollCost = match ($payType) {
            'sleepover' => (float) $rates['sleepover_rate'],
            'on_call' => round($hours * $rates['on_call_rate'], 2),
            'public_holiday' => round($hours * $baseRate * $rates['public_holiday_multiplier'], 2),
            default => round($hours * $baseRate * $rates['regular_multiplier'], 2),
        };

        return [
            'pay_type' => $payType,
            'pay_rate' => $payRate,
            'payroll_cost' => $payrollCost,
        ];
    }

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

        $startsAt = $timesheet->starts_at;
        $dayOfWeek = $timesheet->work_date?->dayOfWeek;

        if ($dayOfWeek !== null && in_array($dayOfWeek, [6, 0], true)) {
            return 'weekend';
        }

        if ($startsAt) {
            $hour = $startsAt->hour;
            if ($hour >= 20 || $hour < 6) {
                return 'night';
            }
            if ($hour >= 18) {
                return 'evening';
            }
        }

        return 'standard';
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
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $timesheet->work_date);
            })
            ->where(function ($query) use ($timesheet) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $timesheet->work_date);
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
