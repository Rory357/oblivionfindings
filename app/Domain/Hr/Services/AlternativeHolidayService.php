<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Models\Timesheet;
use Illuminate\Support\Carbon;

/**
 * Holidays Act 2003 s56: an employee who works on a public holiday that is an
 * otherwise working day gains a whole alternative holiday. Simplified here as
 * one day (contracted weekly hours / 5, default 8h) credited to the worker's
 * 'alternative' leave balance when a public-holiday timesheet is approved.
 * Casual and contractor staff are excluded — see docs/hr-nz-statutory-notes.md.
 */
class AlternativeHolidayService
{
    public function accrueForTimesheet(Timesheet $timesheet): void
    {
        if (! $timesheet->public_holiday || $timesheet->status !== 'approved') {
            return;
        }

        $profile = HrEmployeeProfile::query()
            ->where('user_id', $timesheet->user_id)
            ->first(['id', 'tenant_id', 'employment_type', 'hours_per_week']);

        if (! $profile || in_array((string) $profile->employment_type, ['casual', 'contractor'], true)) {
            return;
        }

        // One accrual per timesheet, ever — survives re-approval cycles.
        $alreadyAccrued = HrLeaveBalanceLedger::query()
            ->where('source_type', 'timesheet')
            ->where('source_id', $timesheet->id)
            ->where('leave_type', 'alternative')
            ->exists();

        if ($alreadyAccrued) {
            return;
        }

        $dayHours = $profile->hours_per_week
            ? round(min(10, max(4, (float) $profile->hours_per_week / 5)), 2)
            : 8.0;

        $workDate = $timesheet->work_date instanceof Carbon
            ? $timesheet->work_date
            : Carbon::parse((string) $timesheet->work_date);
        $year = $workDate->year;

        $balance = HrLeaveBalance::query()->firstOrCreate(
            [
                'tenant_id' => $profile->tenant_id,
                'user_id' => $timesheet->user_id,
                'leave_type' => 'alternative',
                'year' => $year,
            ],
            [
                'source' => 'system',
                'balance_hours' => 0,
                'accrued_hours' => 0,
                'used_hours' => 0,
                'pending_hours' => 0,
            ]
        );

        $beforeBalance = (float) $balance->balance_hours;

        $balance->balance_hours = round($beforeBalance + $dayHours, 2);
        $balance->accrued_hours = round((float) $balance->accrued_hours + $dayHours, 2);
        $balance->last_synced_at = now();
        $balance->save();

        HrLeaveBalanceLedger::create([
            'tenant_id' => $profile->tenant_id,
            'user_id' => $timesheet->user_id,
            'leave_type' => 'alternative',
            'year' => $year,
            'entry_type' => 'accrual',
            'hours_delta' => $dayHours,
            'balance_hours_before' => $beforeBalance,
            'balance_hours_after' => (float) $balance->balance_hours,
            'used_hours_before' => (float) $balance->used_hours,
            'used_hours_after' => (float) $balance->used_hours,
            'pending_hours_before' => (float) $balance->pending_hours,
            'pending_hours_after' => (float) $balance->pending_hours,
            'source_type' => 'timesheet',
            'source_id' => $timesheet->id,
            'notes' => "Alternative holiday for working public holiday on {$workDate->toDateString()}",
        ]);
    }
}
