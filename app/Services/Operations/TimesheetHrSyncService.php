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
                'user_id' => $timesheet->user_id,
                'date' => $timesheet->work_date,
                'hours' => $hours,
                'pay_type' => $payType,
                'description' => sprintf(
                    'Shift timesheet — %s',
                    $timesheet->client?->full_name ?? 'Unknown client'
                ),
                'mileage_km' => $timesheet->mileage_km ?? 0,
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
