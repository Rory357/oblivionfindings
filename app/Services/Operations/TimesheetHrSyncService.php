<?php

namespace App\Services\Operations;

use App\Models\Timesheet;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Support\Facades\Schema;

class TimesheetHrSyncService
{
    public function __construct(
        protected PayrollRateResolver $rateResolver,
        protected ShiftOperationalSnapshotService $snapshots,
    ) {
    }

    public function syncToHr(Timesheet $timesheet): void
    {
        if ($timesheet->status !== 'approved') {
            return;
        }

        if (!Schema::hasTable('hr_time_entries')) {
            return;
        }

        $timesheet->loadMissing([
            'user.hrEmployeeProfile',
            'shift.site:id,name',
            'shift.client:id,first_name,last_name,site_id',
            'shift.serviceContext:id,name',
            'client:id,first_name,last_name',
        ]);

        $timesheet->forceFill($this->snapshots->snapshotForTimesheet($timesheet))->saveQuietly();

        $rate = $this->rateResolver->resolve($timesheet);
        $hours = $this->calculatePayableHours($timesheet);

        // For HR time entry, use dominant_type when mixed so the entry
        // carries a meaningful single classification alongside the cost.
        $hrPayType = $rate['pay_type'] === 'mixed'
            ? ($rate['dominant_type'] ?? 'standard')
            : $rate['pay_type'];

        $entry = \App\Domain\Hr\Models\HrTimeEntry::updateOrCreate(
            [
                'source_type' => 'timesheet',
                'source_id' => $timesheet->id,
            ],
            [
                'tenant_id' => $timesheet->user?->tenant_id,
                'user_id' => $timesheet->user_id,
                'shift_id' => $timesheet->shift_id,
                'client_id' => $timesheet->client_id,
                'site_id' => $timesheet->shift_site_id,
                'entry_date' => $timesheet->work_date,
                'clock_in' => $timesheet->starts_at,
                'clock_out' => $timesheet->ends_at,
                'break_minutes' => $timesheet->break_minutes ?? 0,
                'total_hours' => $hours,
                'entry_type' => 'timesheet',
                'status' => 'approved',
                'pay_type' => $hrPayType,
                'is_sleepover' => (bool) $timesheet->sleepover,
                'is_on_call' => (bool) $timesheet->on_call,
                'is_public_holiday' => (bool) $timesheet->public_holiday,
                'mileage_km' => $timesheet->mileage_km ?? 0,
                'notes' => sprintf(
                    'Shift timesheet — %s',
                    $timesheet->client_name_snapshot ?? 'Snapshot missing'
                ),
                'approved_by' => $timesheet->approved_by,
                'approved_at' => $timesheet->approved_at,
            ]
        );

        $timesheet->forceFill([
            'hr_time_entry_id' => $entry->id,
            'pay_type' => $rate['pay_type'],
            'pay_rate' => $rate['pay_rate'],
        ])->saveQuietly();
    }

    public function mapPayType(Timesheet $timesheet): string
    {
        return $this->rateResolver->mapPayType($timesheet);
    }

    protected function calculatePayableHours(Timesheet $timesheet): float
    {
        if (!$timesheet->starts_at || !$timesheet->ends_at) {
            return 0;
        }

        $minutes = $timesheet->starts_at->diffInMinutes($timesheet->ends_at);
        $breakMinutes = $timesheet->break_minutes ?? 0;

        return round(($minutes - $breakMinutes) / 60, 2);
    }
}
