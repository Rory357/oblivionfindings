<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atomic, dependency-free projection of attendance into the HR time ledger.
 *
 * AttendanceService owns the canonical aggregate locks. This projector only
 * locks downstream Timesheet -> HrTimeEntry rows and never calls back into
 * attendance, Shift lifecycle, or draft-timesheet services.
 */
class AttendanceTimeEntryProjector
{
    public function project(HrAttendanceSession $session, User $actor, array $extra = []): HrTimeEntry
    {
        [$entry, $linkedTimesheet, $workDate, $siteId] = $this->lockProjectionEvidence(
            $session,
            $extra['locked_timesheet'] ?? null,
            (bool) ($extra['payroll_boundary_locked'] ?? false),
        );

        // The attendance command may already have changed its session/Shift in
        // this transaction. Protected downstream evidence therefore blocks the
        // whole command even when the existing ledger projection is coincidentally
        // already byte-identical and would otherwise return through the no-op path.
        $this->assertPayrollMutable($entry, $linkedTimesheet);

        $existing = $entry !== null;
        if (! $entry) {
            $entry = new HrTimeEntry([
                'user_id' => $session->user_id,
                'shift_id' => $session->shift_id,
                'attendance_session_id' => $session->id,
                'site_id' => $siteId,
                'client_id' => $session->shift?->client_id,
                'entry_date' => $workDate,
                'clock_in' => $session->clock_in_at,
                'entry_type' => 'clock',
                'status' => 'active',
                'source_type' => 'attendance',
                'source_id' => $session->id,
                'pay_type' => $session->shift?->is_sleepover
                    ? 'sleepover'
                    : ($session->shift?->is_on_call ? 'on_call' : 'standard'),
                'is_sleepover' => (bool) $session->shift?->is_sleepover,
                'is_on_call' => (bool) $session->shift?->is_on_call,
                'notes' => $extra['notes'] ?? $session->notes,
                'project_code' => $extra['project_code'] ?? null,
                'created_by' => $actor->id,
            ]);
        } else {
            $this->assertIdentity($entry, $session, $siteId);
        }

        $this->backfillCanonicalIdentity($entry, $session, $siteId);
        $entry->entry_date = $workDate;

        if ($session->clock_out_at) {
            $this->fillClockOut(
                $entry,
                $session->clock_out_at,
                (int) ($session->break_minutes ?? 0),
                $extra['mileage_km'] ?? null,
                $extra['notes'] ?? null,
            );
        } elseif ($entry->clock_out) {
            throw new \LogicException('An open attendance session conflicts with its closed time entry.');
        }

        if ($entry->isDirty()) {
            $reason = trim((string) ($extra['amendment_reason'] ?? ''));
            if ($existing && $reason !== '') {
                $this->recordAmendments($entry, $actor, $reason);
            }

            $entry->save();
        }

        $entry = $entry->fresh() ?? $entry;
        if (($extra['link_timesheet'] ?? true)
            && $linkedTimesheet
            && $linkedTimesheet->hr_time_entry_id === null) {
            $linkedTimesheet->forceFill(['hr_time_entry_id' => $entry->id])->saveQuietly();
        }

        return $entry;
    }

    /**
     * Corrections change historical time and therefore fail closed when either
     * time-evidence row, or the canonical worker-local work date, is protected.
     */
    public function assertMutableProjection(
        HrAttendanceSession $session,
        ?Timesheet $lockedTimesheet = null,
        bool $payrollBoundaryLocked = false,
    ): ?HrTimeEntry {
        [$entry, $timesheet] = $this->lockProjectionEvidence(
            $session,
            $lockedTimesheet,
            $payrollBoundaryLocked,
        );
        if ($timesheet?->status === 'approved') {
            throw new \LogicException('The linked timesheet has already been approved — adjust the hours through a timesheet amendment instead.');
        }
        $this->assertPayrollMutable($entry, $timesheet);

        return $entry;
    }

    public function lockApplicationPayrollMutex(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Attendance payroll boundaries must be locked inside a transaction.');
        }
        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();
        if (! $mutex) {
            throw new \LogicException('The application payroll mutex is missing; migration repair is required.');
        }
    }

    /**
     * Enforce the canonical half-open worker interval invariant while the
     * application payroll mutex and worker row are held by the caller.
     * Boundary contact is valid: [08:00, 09:00) does not overlap
     * [09:00, 10:00).
     */
    public function assertNoWorkerTimeOverlap(
        int $userId,
        CarbonInterface $clockIn,
        ?CarbonInterface $clockOut,
        ?int $ignoreEntryId = null,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Worker time overlap checks must run inside a transaction.');
        }

        $query = HrTimeEntry::query()
            ->where('user_id', $userId)
            ->where(function ($status): void {
                $status->whereNull('status')->orWhere('status', '!=', 'voided');
            })
            ->when($ignoreEntryId !== null, fn ($entries) => $entries->whereKeyNot($ignoreEntryId))
            ->where(function ($entries) use ($clockIn): void {
                $entries
                    ->whereNull('clock_out')
                    ->orWhere('clock_out', '>', $clockIn);
            });

        if ($clockOut !== null) {
            $query->where('clock_in', '<', $clockOut);
        }

        if ($query->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) {
            throw new \LogicException('This worker already has an overlapping time entry.');
        }
    }

    /**
     * Lock matching runs after the canonical attendance aggregate and before
     * any Timesheet/HrTimeEntry row. The caller owns the application mutex.
     */
    public function lockPayrollRunsForSession(HrAttendanceSession $session): string
    {
        if (! $session->clock_in_at) {
            throw new \LogicException('Attendance-backed time entries require a clock-in time.');
        }

        $workDate = $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();
        $this->lockPayrollRunsForWorkDate($workDate);

        return $workDate;
    }

    public function lockPayrollRunsForWorkDate(string $workDate): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Attendance payroll boundaries must be locked inside a transaction.');
        }

        $runs = HrPayrollRun::query()
            ->where('period_start', '<=', $workDate)
            ->where('period_end', '>=', $workDate)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'status']);
        if ($runs->contains(fn (HrPayrollRun $run): bool => in_array($run->status, ['locked', 'exported'], true))) {
            throw new \LogicException('This attendance record falls in a locked payroll period.');
        }
    }

    public function applyClockOut(
        HrTimeEntry $entry,
        CarbonInterface $clockOut,
        int $breakMinutes,
        ?float $mileageKm = null,
        ?string $notes = null,
    ): HrTimeEntry {
        $this->fillClockOut($entry, $clockOut, $breakMinutes, $mileageKm, $notes);
        $entry->save();

        return $entry->fresh() ?? $entry;
    }

    private function assertIdentity(HrTimeEntry $entry, HrAttendanceSession $session, int $siteId): void
    {
        if ((int) $entry->user_id !== (int) $session->user_id) {
            throw new \LogicException('Attendance-backed time-entry worker provenance conflicts.');
        }

        $entryShiftId = $this->positiveId($entry->shift_id);
        $sessionShiftId = $this->positiveId($session->shift_id);
        if ($entryShiftId !== null && $entryShiftId !== $sessionShiftId) {
            throw new \LogicException('Attendance-backed time-entry Shift provenance conflicts.');
        }
        if ($sessionShiftId === null && $entryShiftId !== null) {
            throw new \LogicException('Shiftless attendance conflicts with its time-entry Shift provenance.');
        }

        $entrySiteId = $this->positiveId($entry->site_id);
        if ($entrySiteId !== null && $entrySiteId !== $siteId) {
            throw new \LogicException('Attendance-backed time-entry Site provenance conflicts.');
        }

        $entryClientId = $this->positiveId($entry->client_id);
        $sessionClientId = $this->positiveId($session->shift?->client_id);
        if ($entryClientId !== null && $entryClientId !== $sessionClientId) {
            throw new \LogicException('Attendance-backed time-entry Client provenance conflicts.');
        }
        if ($sessionClientId === null && $entryClientId !== null) {
            throw new \LogicException('Shiftless attendance conflicts with its time-entry Client provenance.');
        }

        if ($entry->source_type !== null && $entry->source_type !== 'attendance') {
            throw new \LogicException('Attendance-backed time-entry source provenance conflicts.');
        }
        if ($entry->source_id !== null && (int) $entry->source_id !== (int) $session->id) {
            throw new \LogicException('Attendance-backed time-entry source identity conflicts.');
        }

        if ($entry->clock_in !== null && ! $entry->clock_in->equalTo($session->clock_in_at)) {
            throw new \LogicException('Attendance-backed time-entry clock-in provenance conflicts.');
        }
        if ($entry->entry_type !== null && $entry->entry_type !== 'clock') {
            throw new \LogicException('Attendance-backed time-entry type provenance conflicts.');
        }
    }

    private function backfillCanonicalIdentity(HrTimeEntry $entry, HrAttendanceSession $session, int $siteId): void
    {
        if ($entry->shift_id === null && $session->shift_id !== null) {
            $entry->shift_id = $session->shift_id;
        }
        if ($entry->site_id === null) {
            $entry->site_id = $siteId;
        }
        if ($entry->client_id === null && $session->shift?->client_id !== null) {
            $entry->client_id = $session->shift->client_id;
        }
        if ($entry->source_type === null) {
            $entry->source_type = 'attendance';
        }
        if ($entry->source_id === null) {
            $entry->source_id = $session->id;
        }
        if ($entry->clock_in === null) {
            $entry->clock_in = $session->clock_in_at;
        }
        if ($entry->entry_type === null) {
            $entry->entry_type = 'clock';
        }
    }

    private function fillClockOut(
        HrTimeEntry $entry,
        CarbonInterface $clockOut,
        int $breakMinutes,
        ?float $mileageKm,
        ?string $notes,
    ): void {
        if (! $entry->clock_in || $clockOut->lessThanOrEqualTo($entry->clock_in)) {
            throw new \LogicException('Time-entry clock-out must be after its canonical clock-in.');
        }

        if ($breakMinutes < 0) {
            throw new \LogicException('Break duration cannot be negative.');
        }

        $elapsedMinutes = (int) $entry->clock_in->diffInMinutes($clockOut);
        if ($breakMinutes >= $elapsedMinutes) {
            throw new \LogicException(sprintf(
                'Break duration (%d min) must be less than the session duration (%d min).',
                $breakMinutes,
                $elapsedMinutes,
            ));
        }

        $totalMinutes = $elapsedMinutes - $breakMinutes;

        $entry->clock_out = $clockOut;
        $entry->break_minutes = $breakMinutes;
        $entry->total_hours = round($totalMinutes / 60, 2);
        if ($mileageKm !== null) {
            $entry->mileage_km = $mileageKm;
        }
        $entry->break_compliance_met = $this->meetsNzBreak($totalMinutes, $breakMinutes);
        if ($notes !== null) {
            $entry->notes = $notes;
        }
        if (in_array($entry->status, ['active', null], true)) {
            $entry->status = 'submitted';
        }
    }

    private function meetsNzBreak(int $totalMinutes, int $breakMinutes): bool
    {
        $workedHours = $totalMinutes / 60;
        $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);

        return $breakMinutes >= $requiredBreak;
    }

    private function assertPayrollMutable(?HrTimeEntry $entry, ?Timesheet $timesheet): void
    {
        if ($entry?->status === 'approved') {
            throw new \LogicException('An approved time entry cannot be changed through attendance.');
        }

        if ($timesheet && (
            $timesheet->is_payroll_segment_complete
            || filled($timesheet->payroll_reference)
            || $timesheet->exported_to_payroll_at !== null
        )) {
            throw new \LogicException('This attendance record is linked to protected payroll evidence.');
        }

        // Payroll-run status was locked under the application mutex before any
        // Timesheet/HrTimeEntry lock; do not reintroduce an unlocked TOCTOU read.
    }

    /**
     * @return array{0: HrTimeEntry|null, 1: Timesheet|null, 2: string, 3: int}
     */
    private function lockProjectionEvidence(
        HrAttendanceSession $session,
        ?Timesheet $expectedTimesheet = null,
        bool $payrollBoundaryLocked = false,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Attendance time projection requires the canonical attendance transaction.');
        }

        $session->loadMissing('shift');
        if (! $session->clock_in_at) {
            throw new \LogicException('Attendance-backed time entries require a clock-in time.');
        }

        $siteId = $this->positiveId($session->site_id);
        if ($siteId === null) {
            throw new \LogicException('Attendance-backed time entries require canonical Site provenance.');
        }

        if (! $payrollBoundaryLocked) {
            throw new \LogicException(
                'Attendance projection must enter through the canonical payroll and attendance lock path.',
            );
        }
        $workDate = $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();

        // Snapshot identity only selects downstream rows to lock. The unique
        // attendance key is re-read under lock before any decision or write.
        $entrySnapshot = HrTimeEntry::withTrashed()
            ->where('attendance_session_id', $session->id)
            ->first(['id']);

        $linkedTimesheets = Timesheet::query()
            ->where(function ($query) use ($session, $entrySnapshot): void {
                $query->where('attendance_session_id', $session->id);
                if ($entrySnapshot) {
                    $query->orWhere('hr_time_entry_id', $entrySnapshot->id);
                }
                if ($session->shift_id) {
                    $query->orWhere(function ($fallback) use ($session): void {
                        $fallback
                            ->where('shift_id', $session->shift_id)
                            ->where('user_id', $session->user_id);
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($linkedTimesheets->count() > 1) {
            throw new \LogicException('Attendance is linked to conflicting time evidence.');
        }
        $linkedTimesheet = $linkedTimesheets->first();
        if ($expectedTimesheet && (int) $expectedTimesheet->id !== (int) ($linkedTimesheet?->id ?? 0)) {
            throw new \LogicException('Attendance Timesheet identity changed while it was being locked.');
        }

        $entry = HrTimeEntry::withTrashed()
            ->where('attendance_session_id', $session->id)
            ->lockForUpdate()
            ->first();

        if ($entrySnapshot && (! $entry || (int) $entry->id !== (int) $entrySnapshot->id)) {
            throw new \LogicException('Attendance-backed time-entry identity changed while it was being locked.');
        }
        if ($entry?->trashed() || $entry?->status === 'voided') {
            throw new \LogicException('A voided attendance-backed time entry cannot be restored.');
        }
        if ($entry) {
            $this->assertIdentity($entry, $session, $siteId);
            if ($entry->entry_date?->toDateString() !== $workDate) {
                throw new \LogicException('Attendance-backed time-entry work-date provenance conflicts.');
            }
        }
        if ($linkedTimesheet) {
            $this->assertTimesheetIdentity($linkedTimesheet, $entry, $session, $siteId);
        }

        return [$entry, $linkedTimesheet, $workDate, $siteId];
    }

    private function assertTimesheetIdentity(
        Timesheet $timesheet,
        ?HrTimeEntry $entry,
        HrAttendanceSession $session,
        int $siteId,
    ): void {
        if ($timesheet->attendance_session_id !== null
            && (int) $timesheet->attendance_session_id !== (int) $session->id) {
            throw new \LogicException('Attendance Timesheet session provenance conflicts.');
        }
        if ($timesheet->hr_time_entry_id !== null
            && (! $entry || (int) $timesheet->hr_time_entry_id !== (int) $entry->id)) {
            throw new \LogicException('Attendance Timesheet time-entry provenance conflicts.');
        }
        if ((int) $timesheet->user_id !== (int) $session->user_id) {
            throw new \LogicException('Attendance Timesheet worker provenance conflicts.');
        }

        $timesheetShiftId = $this->positiveId($timesheet->shift_id);
        $sessionShiftId = $this->positiveId($session->shift_id);
        if ($timesheetShiftId !== $sessionShiftId) {
            throw new \LogicException('Attendance Timesheet Shift provenance conflicts.');
        }

        foreach ([$timesheet->site_id, $timesheet->shift_site_id] as $timesheetSiteId) {
            $capturedSiteId = $this->positiveId($timesheetSiteId);
            if ($capturedSiteId !== null && $capturedSiteId !== $siteId) {
                throw new \LogicException('Attendance Timesheet Site provenance conflicts.');
            }
        }
    }

    private function recordAmendments(HrTimeEntry $entry, User $actor, string $reason): void
    {
        $tracked = ['entry_date', 'clock_out', 'break_minutes', 'site_id'];
        $originalValues = [];

        foreach ($tracked as $field) {
            if (! $entry->isDirty($field)) {
                continue;
            }

            $oldValue = $entry->getOriginal($field);
            $newValue = $entry->getAttribute($field);
            $originalValues[$field] = $this->serialiseValue($oldValue);

            HrTimeEntryAmendment::query()->create([
                'hr_time_entry_id' => $entry->id,
                'amended_by' => $actor->id,
                'field_name' => $field,
                'old_value' => $this->serialiseValue($oldValue),
                'new_value' => $this->serialiseValue($newValue),
                'reason' => $reason,
            ]);
        }

        if ($originalValues !== []) {
            $entry->amended_by = $actor->id;
            $entry->amended_at = now();
            $entry->amendment_reason = $reason;
            $entry->original_values = array_merge((array) $entry->original_values, $originalValues);
        }
    }

    private function serialiseValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->toDateTimeString()
            : (string) $value;
    }

    private function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
