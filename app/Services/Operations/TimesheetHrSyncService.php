<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceTimeEntryProjector;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftOperationalSnapshotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TimesheetHrSyncService
{
    public function __construct(
        protected PayrollRateResolver $rateResolver,
        protected ShiftOperationalSnapshotService $snapshots,
        protected AttendanceTimeEntryProjector $timeEntries,
    ) {}

    public function syncToHr(
        Timesheet $timesheet,
        ?HrTimeEntry $preparedEntry = null,
        bool $payrollBoundaryPrepared = false,
    ): void {
        if ($timesheet->status !== 'approved') {
            return;
        }

        if (! Schema::hasTable('hr_time_entries')) {
            return;
        }

        if ($payrollBoundaryPrepared && DB::transactionLevel() < 1) {
            throw new \LogicException('Prepared Timesheet HR sync requires an active transaction.');
        }

        DB::transaction(function () use ($timesheet, $preparedEntry, $payrollBoundaryPrepared): void {
            if ($payrollBoundaryPrepared) {
                $lockedTimesheet = $timesheet;
                $entry = $preparedEntry;
            } else {
                $this->timeEntries->lockApplicationPayrollMutex();
                $lockedTimesheet = Timesheet::query()
                    ->lockForUpdate()
                    ->findOrFail($timesheet->id);
                $entry = $this->lockCanonicalEntryForMutation($lockedTimesheet);
                $this->assertNoWorkerOverlapForMutation($lockedTimesheet, $entry);
                $this->lockPayrollRunsForDates([
                    $lockedTimesheet->work_date,
                    $entry?->entry_date,
                ]);
            }

            if ($lockedTimesheet->status !== 'approved') {
                return;
            }

            if ($lockedTimesheet->hasActivePayrollClaim()) {
                throw ValidationException::withMessages([
                    'timesheet' => 'This timesheet is claimed by an active payroll run. Correct the draft payroll run before synchronising HR evidence.',
                ]);
            }

            $lockedTimesheet->load([
                'user.hrEmployeeProfile',
                'shift.site:id,name',
                'shift.client:id,first_name,last_name,site_id',
                'shift.serviceContext:id,name',
                'client:id,first_name,last_name',
            ]);
            $lockedTimesheet
                ->forceFill($this->snapshots->snapshotForTimesheet($lockedTimesheet))
                ->saveQuietly();

            $this->assertPayableInterval($lockedTimesheet);
            $rate = $this->rateResolver->resolve($lockedTimesheet);
            $tenantId = $this->resolveTenantId($lockedTimesheet);
            $hrPayType = $rate['pay_type'] === 'mixed'
                ? ($rate['dominant_type'] ?? 'standard')
                : $rate['pay_type'];
            $entryValues = [
                'tenant_id' => $tenantId,
                'user_id' => $lockedTimesheet->user_id,
                'shift_id' => $lockedTimesheet->shift_id,
                'client_id' => $lockedTimesheet->client_id,
                'site_id' => $lockedTimesheet->shift_site_id,
                'entry_date' => $lockedTimesheet->work_date,
                'clock_in' => $lockedTimesheet->starts_at,
                'clock_out' => $lockedTimesheet->ends_at,
                'break_minutes' => $lockedTimesheet->break_minutes ?? 0,
                'total_hours' => $this->calculatePayableHours($lockedTimesheet),
                'entry_type' => 'timesheet',
                'status' => 'approved',
                'pay_type' => $hrPayType,
                'is_sleepover' => (bool) $lockedTimesheet->sleepover,
                'is_on_call' => (bool) $lockedTimesheet->on_call,
                'is_public_holiday' => (bool) $lockedTimesheet->public_holiday,
                'mileage_km' => $lockedTimesheet->mileage_km ?? 0,
                'notes' => sprintf(
                    'Shift timesheet — %s',
                    $lockedTimesheet->client_name_snapshot ?? 'Snapshot missing',
                ),
                'approved_by' => $lockedTimesheet->approved_by,
                'approved_at' => $lockedTimesheet->approved_at,
            ];

            if ($entry) {
                $this->assertEntryIdentity($entry, $lockedTimesheet, $tenantId);
                $attendanceBacked = $this->isAttendanceEntryForTimesheet($entry, $lockedTimesheet);
                $values = $attendanceBacked ? [
                    // AttendanceService owns interval and provenance. Approval
                    // enriches workflow state only and cannot rewrite it from
                    // a drifted Timesheet snapshot.
                    'status' => 'approved',
                    'approved_by' => $lockedTimesheet->approved_by,
                    'approved_at' => $lockedTimesheet->approved_at,
                ] : $entryValues;
                $entry->fill([
                    ...($attendanceBacked ? [] : [
                        'source_type' => 'timesheet',
                        'source_id' => $lockedTimesheet->id,
                    ]),
                    ...$values,
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

    /**
     * Lock and resolve the one canonical HR entry selected by a locked
     * Timesheet. Callers take this seam after Timesheet and before payroll-run
     * locks so both the entry's old date and the Timesheet date are protected.
     */
    public function lockCanonicalEntryForMutation(Timesheet $timesheet): ?HrTimeEntry
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Timesheet HR identity must be resolved inside a transaction.');
        }

        $worker = User::query()
            ->whereKey($timesheet->user_id)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($worker !== null, 404);

        $canonicalEntries = HrTimeEntry::withTrashed()
            ->where('source_type', 'timesheet')
            ->where('source_id', $timesheet->id)
            ->orderBy('id')
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
        if ($timesheet->attendance_session_id !== null) {
            $attendanceEntries = HrTimeEntry::withTrashed()
                ->where('attendance_session_id', $timesheet->attendance_session_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(2)
                ->get();
            if ($attendanceEntries->count() !== 1) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Attendance-backed approval requires exactly one canonical attendance HR time entry. Repair the attendance projection before approval.',
                ]);
            }

            $linkedEntry = $attendanceEntries->first();
            if ($linkedEntry->trashed()
                || $linkedEntry->status === 'voided'
                || $linkedEntry->source_type !== 'attendance'
                || (int) $linkedEntry->source_id !== (int) $timesheet->attendance_session_id) {
                throw ValidationException::withMessages([
                    'timesheet' => 'The attendance HR time entry has invalid source provenance. Repair it through the governed attendance workflow before approval.',
                ]);
            }
            if ($timesheet->hr_time_entry_id !== null
                && (int) $timesheet->hr_time_entry_id !== (int) $linkedEntry->id) {
                throw ValidationException::withMessages([
                    'timesheet' => 'The Timesheet link conflicts with its canonical attendance HR time entry.',
                ]);
            }
            if ($canonicalEntry && $canonicalEntry->id !== $linkedEntry->id) {
                throw ValidationException::withMessages([
                    'timesheet' => 'This timesheet has conflicting HR time-entry identities. Resolve the duplicate before approval.',
                ]);
            }

            $this->assertAttendanceProvenance($timesheet, $linkedEntry);
        } elseif ($timesheet->hr_time_entry_id !== null) {
            $linkedEntry = HrTimeEntry::withTrashed()
                ->whereKey($timesheet->hr_time_entry_id)
                ->lockForUpdate()
                ->first();
            if (! $linkedEntry || $linkedEntry->trashed() || $linkedEntry->status === 'voided') {
                throw ValidationException::withMessages([
                    'timesheet' => 'The linked HR time entry is unavailable. Resolve the link before approval.',
                ]);
            }
            if ($canonicalEntry && $canonicalEntry->id !== $linkedEntry->id) {
                throw ValidationException::withMessages([
                    'timesheet' => 'This timesheet has conflicting HR time-entry identities. Resolve the duplicate before approval.',
                ]);
            }
        }

        $entry = $linkedEntry ?? $canonicalEntry;
        if ($entry && ($entry->trashed()
            || $entry->status === 'voided'
            || (int) $entry->user_id !== (int) $timesheet->user_id)) {
            throw ValidationException::withMessages([
                'timesheet' => 'The linked HR time entry is unavailable or belongs to a different staff member.',
            ]);
        }
        if ($entry
            && ! $this->isAttendanceEntryForTimesheet($entry, $timesheet)
            && $entry->source_type !== null
            && ($entry->source_type !== 'timesheet' || (int) $entry->source_id !== (int) $timesheet->id)) {
            throw ValidationException::withMessages([
                'timesheet' => 'The linked HR time entry already belongs to a different source record.',
            ]);
        }

        return $entry;
    }

    /**
     * Lock the worker's complete half-open interval set before payroll reads.
     * The application mutex and target User row are already held by the same
     * boundary that resolved the canonical entry.
     */
    public function assertNoWorkerOverlapForMutation(Timesheet $timesheet, ?HrTimeEntry $entry): void
    {
        $this->assertPayableInterval($timesheet);
        $this->timeEntries->assertNoWorkerTimeOverlap(
            (int) $timesheet->user_id,
            $timesheet->starts_at,
            $timesheet->ends_at,
            $entry?->id,
        );
    }

    protected function assertAttendanceProvenance(Timesheet $timesheet, HrTimeEntry $entry): void
    {
        $sessionSnapshot = HrAttendanceSession::query()
            ->whereKey($timesheet->attendance_session_id)
            ->first(['id', 'user_id', 'shift_id']);
        if (! $sessionSnapshot || (int) $sessionSnapshot->user_id !== (int) $timesheet->user_id) {
            $this->throwAttendanceProvenanceMismatch();
        }

        $shift = null;
        $client = null;
        if ($sessionSnapshot->shift_id !== null) {
            $shiftSnapshot = Shift::query()
                ->whereKey($sessionSnapshot->shift_id)
                ->first(['id', 'client_id']);
            if (! $shiftSnapshot || ! $shiftSnapshot->client_id) {
                $this->throwAttendanceProvenanceMismatch();
            }
            $client = Client::query()
                ->whereKey($shiftSnapshot->client_id)
                ->lockForUpdate()
                ->first(['id', 'site_id']);
            $shift = Shift::query()
                ->whereKey($shiftSnapshot->id)
                ->where('client_id', $client?->id)
                ->lockForUpdate()
                ->first(['id', 'user_id', 'client_id', 'site_id']);
            if (! $client || ! $shift || (int) $shift->user_id !== (int) $timesheet->user_id) {
                $this->throwAttendanceProvenanceMismatch();
            }
            $shift->setRelation('client', $client);
        }

        $session = HrAttendanceSession::query()
            ->whereKey($sessionSnapshot->id)
            ->where('user_id', $timesheet->user_id)
            ->when(
                $shift,
                fn ($query) => $query->where('shift_id', $shift->id),
                fn ($query) => $query->whereNull('shift_id'),
            )
            ->lockForUpdate()
            ->first();
        if (! $session || ! $session->clock_in_at || ! $session->clock_out_at || $session->status !== 'closed') {
            $this->throwAttendanceProvenanceMismatch();
        }
        $session->setRelation('shift', $shift);

        $siteIds = collect([
            $session->site_id,
            $shift?->site_id,
            $client?->site_id,
        ])
            ->filter(fn ($siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn ($siteId): int => (int) $siteId)
            ->unique()
            ->values();
        if ($siteIds->count() !== 1) {
            $this->throwAttendanceProvenanceMismatch();
        }
        $siteId = (int) $siteIds->first();
        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->lockForUpdate()
            ->first(['id']);
        if (! $site) {
            $this->throwAttendanceProvenanceMismatch();
        }

        $workDate = $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();
        $expectedHours = round(
            ($session->clock_in_at->diffInMinutes($session->clock_out_at) - (int) ($session->break_minutes ?? 0)) / 60,
            2,
        );
        $timesheetSiteId = $shift ? $timesheet->shift_site_id : $timesheet->site_id;
        $canonicalClientId = $client?->id;
        $matches = (int) $timesheet->user_id === (int) $session->user_id
            && ($timesheet->shift_id === null ? null : (int) $timesheet->shift_id)
                === ($session->shift_id === null ? null : (int) $session->shift_id)
            && ($timesheet->client_id === null ? null : (int) $timesheet->client_id)
                === ($canonicalClientId === null ? null : (int) $canonicalClientId)
            && (int) $timesheetSiteId === $siteId
            && $timesheet->work_date?->toDateString() === $workDate
            && $this->sameTimestamp($timesheet->starts_at, $session->clock_in_at)
            && $this->sameTimestamp($timesheet->ends_at, $session->clock_out_at)
            && (int) ($timesheet->break_minutes ?? 0) === (int) ($session->break_minutes ?? 0)
            && (int) $entry->user_id === (int) $session->user_id
            && ($entry->shift_id === null ? null : (int) $entry->shift_id)
                === ($session->shift_id === null ? null : (int) $session->shift_id)
            && ($entry->client_id === null ? null : (int) $entry->client_id)
                === ($canonicalClientId === null ? null : (int) $canonicalClientId)
            && (int) $entry->site_id === $siteId
            && $entry->entry_date?->toDateString() === $workDate
            && $this->sameTimestamp($entry->clock_in, $session->clock_in_at)
            && $this->sameTimestamp($entry->clock_out, $session->clock_out_at)
            && (int) ($entry->break_minutes ?? 0) === (int) ($session->break_minutes ?? 0)
            && abs((float) $entry->total_hours - $expectedHours) < 0.001;
        if (! $matches) {
            $this->throwAttendanceProvenanceMismatch();
        }

        $this->assertPayableInterval($timesheet);
    }

    protected function sameTimestamp(mixed $left, mixed $right): bool
    {
        return $left instanceof \DateTimeInterface
            && $right instanceof \DateTimeInterface
            && $left->getTimestamp() === $right->getTimestamp();
    }

    protected function throwAttendanceProvenanceMismatch(): never
    {
        throw ValidationException::withMessages([
            'timesheet' => 'Attendance-backed approval no longer matches its canonical session and projection. Repair it through the governed attendance workflow.',
        ]);
    }

    /** @param array<int, mixed> $dates */
    protected function lockPayrollRunsForDates(array $dates): void
    {
        collect($dates)
            ->filter(fn ($date): bool => filled($date))
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->each(function (string $date): void {
                $runs = HrPayrollRun::query()
                    ->where('period_start', '<=', $date)
                    ->where('period_end', '>=', $date)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'status']);
                if ($runs->contains(fn (HrPayrollRun $run): bool => in_array($run->status, ['locked', 'exported'], true))) {
                    throw ValidationException::withMessages([
                        'timesheet' => 'This timesheet is locked by a payroll run and cannot synchronise HR evidence.',
                    ]);
                }
            });
    }

    protected function assertPayableInterval(Timesheet $timesheet): void
    {
        if (! $timesheet->starts_at || ! $timesheet->ends_at || $timesheet->ends_at->lessThanOrEqualTo($timesheet->starts_at)) {
            throw ValidationException::withMessages([
                'timesheet' => 'A payable timesheet requires an end time after its start time.',
            ]);
        }
        $elapsedMinutes = (int) $timesheet->starts_at->diffInMinutes($timesheet->ends_at);
        $breakMinutes = (int) ($timesheet->break_minutes ?? 0);
        if ($breakMinutes < 0 || $breakMinutes >= $elapsedMinutes) {
            throw ValidationException::withMessages([
                'timesheet' => 'Timesheet break minutes must be non-negative and less than the elapsed interval.',
            ]);
        }
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

        if ($this->isAttendanceEntryForTimesheet($entry, $timesheet)) {
            return;
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

    protected function isAttendanceEntryForTimesheet(HrTimeEntry $entry, Timesheet $timesheet): bool
    {
        return $entry->source_type === 'attendance'
            && $timesheet->attendance_session_id !== null
            && $entry->attendance_session_id !== null
            && (int) $entry->attendance_session_id === (int) $timesheet->attendance_session_id
            && (int) $entry->source_id === (int) $timesheet->attendance_session_id;
    }
}
