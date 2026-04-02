<?php

namespace App\Services\Operations;

use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class TimesheetHrSyncService
{
    public function syncToHr(Timesheet $timesheet): void
    {
        if ($timesheet->status !== 'approved') {
            return;
        }

        if (!Schema::hasTable('hr_time_entries')) {
            return;
        }

        $payType = $this->mapPayType($timesheet);
        $hours = $this->calculatePayableHours($timesheet);

        \App\Domain\Hr\Models\HrTimeEntry::updateOrCreate(
            [
                'source_type' => 'timesheet',
                'source_id' => $timesheet->id,
            ],
            [
                'tenant_id' => $timesheet->user?->tenant_id,
                'user_id' => $timesheet->user_id,
                'shift_id' => $timesheet->shift_id,
                'client_id' => $timesheet->client_id,
                'entry_date' => $timesheet->work_date,
                'clock_in' => $timesheet->starts_at,
                'clock_out' => $timesheet->ends_at,
                'break_minutes' => $timesheet->break_minutes ?? 0,
                'total_hours' => $hours,
                'entry_type' => 'timesheet',
                'status' => 'approved',
                'pay_type' => $payType,
                'is_sleepover' => (bool) $timesheet->sleepover,
                'is_on_call' => (bool) $timesheet->on_call,
                'is_public_holiday' => (bool) $timesheet->public_holiday,
                'mileage_km' => $timesheet->mileage_km ?? 0,
                'notes' => sprintf(
                    'Shift timesheet — %s',
                    $timesheet->client?->full_name ?? 'Unknown client'
                ),
                'approved_by' => $timesheet->approved_by,
                'approved_at' => $timesheet->approved_at,
            ]
        );

        $timesheet->update(['exported_to_payroll_at' => now()]);
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

        $dayOfWeek = Carbon::parse($timesheet->work_date)->dayOfWeek;
        if (in_array($dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
            return 'weekend';
        }

        if ($timesheet->starts_at) {
            $hour = Carbon::parse($timesheet->starts_at)->hour;
            if ($hour >= 20 || $hour < 6) {
                return 'night';
            }
            if ($hour >= 18) {
                return 'evening';
            }
        }

        return 'standard';
    }

    protected function calculatePayableHours(Timesheet $timesheet): float
    {
        if (!$timesheet->starts_at || !$timesheet->ends_at) {
            return 0;
        }

        $minutes = Carbon::parse($timesheet->starts_at)->diffInMinutes(Carbon::parse($timesheet->ends_at));
        $breakMinutes = $timesheet->break_minutes ?? 0;

        return round(($minutes - $breakMinutes) / 60, 2);
    }
}
