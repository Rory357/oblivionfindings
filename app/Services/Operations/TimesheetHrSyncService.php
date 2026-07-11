<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Timesheet;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TimesheetHrSyncService
{
    public function __construct(
        protected PayrollRateResolver $rateResolver,
        protected ShiftOperationalSnapshotService $snapshots,
    ) {}

    public function syncToHr(Timesheet $timesheet): void
    {
        if ($timesheet->status !== 'approved') {
            return;
        }

        if (! Schema::hasTable('hr_time_entries')) {
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
        $tenantId = $this->resolveTenantId($timesheet);

        // For HR time entry, use dominant_type when mixed so the entry
        // carries a meaningful single classification alongside the cost.
        $hrPayType = $rate['pay_type'] === 'mixed'
            ? ($rate['dominant_type'] ?? 'standard')
            : $rate['pay_type'];

        $entryValues = [
            'tenant_id' => $tenantId,
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
        ];

        DB::transaction(function () use ($timesheet, $tenantId, $entryValues, $rate): void {
            // The timesheet row is the concurrency gate. It makes the
            // source lookup + optional create atomic even though historical
            // schemas only indexed (rather than uniquely constrained) source.
            $lockedTimesheet = Timesheet::query()
                ->lockForUpdate()
                ->findOrFail($timesheet->id);

            $canonicalEntries = HrTimeEntry::query()
                ->where('source_type', 'timesheet')
                ->where('source_id', $lockedTimesheet->id)
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($canonicalEntries->count() > 1) {
                throw ValidationException::withMessages([
                    'timesheet' => 'This timesheet has duplicate canonical HR time entries. Resolve the duplicates before approval.',
                ]);
            }

            $canonicalEntry = $canonicalEntries->first();

            $linkedEntry = null;
            if ($lockedTimesheet->hr_time_entry_id) {
                $linkedEntry = HrTimeEntry::query()
                    ->lockForUpdate()
                    ->find($lockedTimesheet->hr_time_entry_id);

                if (! $linkedEntry) {
                    throw ValidationException::withMessages([
                        'timesheet' => 'The linked HR time entry no longer exists. Resolve the link before approval.',
                    ]);
                }

                if ($canonicalEntry && $canonicalEntry->id !== $linkedEntry->id) {
                    throw ValidationException::withMessages([
                        'timesheet' => 'This timesheet has conflicting HR time-entry identities. Resolve the duplicate before approval.',
                    ]);
                }
            }

            $entry = $linkedEntry ?? $canonicalEntry;

            if ($entry) {
                $this->assertEntryIdentity($entry, $lockedTimesheet, $tenantId);
                $entry->fill([
                    'source_type' => 'timesheet',
                    'source_id' => $lockedTimesheet->id,
                    ...$entryValues,
                ])->save();
            } else {
                $entry = HrTimeEntry::query()->create([
                    'source_type' => 'timesheet',
                    'source_id' => $lockedTimesheet->id,
                    'created_by' => $lockedTimesheet->approved_by,
                    ...$entryValues,
                ]);
            }

            $lockedTimesheet->forceFill([
                'hr_time_entry_id' => $entry->id,
                'pay_type' => $rate['pay_type'],
                'pay_rate' => $rate['pay_rate'],
            ])->saveQuietly();
        });
    }

    public function mapPayType(Timesheet $timesheet): string
    {
        return $this->rateResolver->mapPayType($timesheet);
    }

    protected function calculatePayableHours(Timesheet $timesheet): float
    {
        if (! $timesheet->starts_at || ! $timesheet->ends_at) {
            return 0;
        }

        $minutes = $timesheet->starts_at->diffInMinutes($timesheet->ends_at);
        $breakMinutes = $timesheet->break_minutes ?? 0;

        return round(($minutes - $breakMinutes) / 60, 2);
    }

    protected function resolveTenantId(Timesheet $timesheet): int
    {
        $tenantId = $timesheet->user?->tenant_id
            ?? $timesheet->user?->hrEmployeeProfile?->tenant_id
            ?? HrEmployeeProfile::query()->where('user_id', $timesheet->user_id)->value('tenant_id');

        if (is_numeric($tenantId)) {
            return (int) $tenantId;
        }

        throw ValidationException::withMessages([
            'timesheet' => 'This approved timesheet cannot sync to HR because no tenant context could be resolved for the staff member.',
        ]);
    }

    protected function assertEntryIdentity(HrTimeEntry $entry, Timesheet $timesheet, int $tenantId): void
    {
        if ((int) $entry->tenant_id !== $tenantId || (int) $entry->user_id !== (int) $timesheet->user_id) {
            throw ValidationException::withMessages([
                'timesheet' => 'The linked HR time entry belongs to a different staff member or organisation.',
            ]);
        }

        if ($entry->source_type !== null && (
            $entry->source_type !== 'timesheet'
            || (int) $entry->source_id !== (int) $timesheet->id
        )) {
            throw ValidationException::withMessages([
                'timesheet' => 'The linked HR time entry already belongs to a different source record.',
            ]);
        }
    }
}
