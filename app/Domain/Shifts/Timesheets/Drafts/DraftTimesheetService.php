<?php

namespace App\Domain\Shifts\Timesheets\Drafts;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\PublicHolidayCalendar;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\PayrollRateResolver;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftOperationalSnapshotService;

class DraftTimesheetService
{
    public function __construct(
        protected ShiftOperationalSnapshotService $snapshots,
        protected TimesheetReconciliationService $reconciler,
        protected PayrollRateResolver $payrollRates,
        protected PublicHolidayCalendar $publicHolidays,
    ) {}

    /**
     * Whether the given worker-local work date is a public holiday for the
     * workplace (national always; regional anniversaries by site region).
     */
    protected function isPublicHolidayFor(string $workDate, ?int $siteId): bool
    {
        $region = $siteId
            ? Site::query()->whereKey($siteId)->value('region')
            : null;

        return $this->publicHolidays->isPublicHoliday($workDate, $region);
    }

    /**
     * @return array{success: bool, reason: string|null, timesheet: Timesheet|null}
     */
    public function fromShift(Shift $shift, int $actorId): array
    {
        if (! $shift->user_id || ! $shift->client_id) {
            return ['success' => false, 'reason' => 'Shift is missing assigned staff or client.', 'timesheet' => null];
        }

        $shift->loadMissing([
            'attendanceSessions:id,user_id,shift_id,clock_in_at,clock_out_at,break_minutes,status',
            'client:id,first_name,last_name,site_id',
            'client.site:id,name',
            'site:id,name',
            'serviceContext:id,name',
            'staff:id,name',
        ]);

        $startsAt = $shift->actual_starts_at ?? $shift->starts_at;
        $endsAt = $shift->actual_ends_at ?? $shift->ends_at;

        if (! $startsAt || ! $endsAt) {
            return ['success' => false, 'reason' => 'Shift has no start/end times to base timesheet on.', 'timesheet' => null];
        }

        $matchingAttendanceSessions = $shift->attendanceSessions
            ->where('user_id', $shift->user_id)
            ->values();
        $uniqueAttendanceSession = $matchingAttendanceSessions->count() === 1
            ? $matchingAttendanceSessions->first()
            : null;

        $timesheet = Timesheet::query()->firstOrNew([
            'shift_id' => $shift->id,
            'user_id' => $shift->user_id,
        ]);

        if ($timesheet->exists && ! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return ['success' => true, 'reason' => null, 'timesheet' => $timesheet];
        }

        $snapshot = $this->snapshots->snapshotForShift($shift, $shift->staff);

        $workDate = $startsAt->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();

        $timesheet->fill([
            'user_id' => $shift->user_id,
            'client_id' => $shift->client_id,
            'shift_site_id' => $snapshot['site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $timesheet->shift_service_context_id,
            'attendance_session_id' => $timesheet->attendance_session_id ?: $uniqueAttendanceSession?->id,
            'work_date' => $workDate,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => (int) ($shift->expected_break_minutes ?? $timesheet->break_minutes ?? 0),
            'sleepover' => (bool) $shift->is_sleepover,
            'on_call' => (bool) $shift->is_on_call,
            'public_holiday' => $this->isPublicHolidayFor(
                $workDate,
                $snapshot['site_id'] ?? $shift->client?->site_id,
            ),
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['location'] ?? $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name'] ?? $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? $timesheet->shift_type_snapshot ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $timesheet->coverage_roles_snapshot ?? [],
            'status' => 'draft',
        ]);

        if (! $timesheet->exists) {
            $timesheet->created_by = $actorId;
        }

        $timesheet->save();

        $this->reconciler->reconcile($timesheet, $uniqueAttendanceSession);

        return ['success' => true, 'reason' => null, 'timesheet' => $timesheet->fresh() ?? $timesheet];
    }

    public function fromAttendanceSession(HrAttendanceSession $session, int $actorId, array $data = []): ?Timesheet
    {
        if (! $session->clock_out_at) {
            return null;
        }

        $session->loadMissing([
            'shift.client:id,first_name,last_name,site_id',
            'shift.site:id,name',
            'shift.serviceContext:id,name',
            'shift.staff:id,name',
        ]);

        $timesheet = Timesheet::query()
            ->where('attendance_session_id', $session->id)
            ->first();

        if (! $timesheet && $session->shift_id) {
            $timesheet = Timesheet::query()
                ->where('shift_id', $session->shift_id)
                ->where('user_id', $session->user_id)
                ->first();
        }

        $clientId = $session->shift?->client_id ?? ($data['client_id'] ?? null);

        // A clock-out with no rostered shift must still log the worker's time
        // ("shift or no shift, their time is logged"). client_id is nullable on
        // timesheets, so we record a non-shift draft the worker can categorise
        // and allocate before submitting instead of dropping the session.
        $isNonShift = ! $session->shift_id;

        $snapshot = $session->shift
            ? $this->snapshots->snapshotForShift($session->shift, User::query()->find($session->user_id))
            : null;

        $sessionBreakMinutes = (int) ($session->break_minutes ?? 0);
        $existingBreakMinutes = $timesheet && $timesheet->exists && $timesheet->break_minutes !== null
            ? (int) $timesheet->break_minutes
            : null;
        $breakMinutes = $existingBreakMinutes !== null
            ? max($existingBreakMinutes, $sessionBreakMinutes)
            : $sessionBreakMinutes;

        // Work date in the worker's timezone — a 09:00 NZT clock-in is 21:00
        // UTC the previous day, so the raw UTC date would mis-date the sheet.
        $workDate = $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();

        $payload = [
            'user_id' => $session->user_id,
            'client_id' => $clientId,
            'shift_id' => $session->shift_id,
            'activity_type' => $isNonShift ? 'other' : null,
            'site_id' => $isNonShift ? $session->site_id : null,
            'shift_site_id' => $snapshot['site_id'] ?? null,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? null,
            'work_date' => $workDate,
            'starts_at' => $session->clock_in_at,
            'ends_at' => $session->clock_out_at,
            'break_minutes' => $breakMinutes,
            'sleepover' => (bool) ($session->shift?->is_sleepover ?? false),
            'on_call' => (bool) ($session->shift?->is_on_call ?? false),
            'public_holiday' => $this->isPublicHolidayFor(
                $workDate,
                $snapshot['site_id'] ?? $session->site_id,
            ),
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? null,
            'shift_location_snapshot' => $snapshot['location'] ?? null,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? null,
            'client_name_snapshot' => $snapshot['client_name'] ?? null,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? null,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? null,
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? [],
            'status' => 'draft',
            'created_by' => $actorId,
        ];

        if (! $timesheet) {
            Timesheet::ensureNoDuplicateShiftUserPair(
                $session->shift_id ? (int) $session->shift_id : null,
                (int) $session->user_id,
            );

            $timesheet = Timesheet::query()->create([
                ...$payload,
                'attendance_session_id' => $session->id,
            ]);
        } elseif (in_array($timesheet->status, ['draft', 'returned'], true)) {
            $timesheet->update([
                ...$payload,
                'attendance_session_id' => $timesheet->attendance_session_id ?: $session->id,
            ]);
            $timesheet = $timesheet->fresh();
        } else {
            $this->reconciler->reconcile($timesheet->fresh(), $session);

            return $timesheet->fresh();
        }

        $timesheet->loadMissing(['shift.site:id,name', 'shift.client:id,first_name,last_name,site_id', 'shift.serviceContext:id,name', 'user.hrEmployeeProfile']);
        $rate = $this->payrollRates->resolve($timesheet);
        $timesheet->forceFill([
            'pay_type' => $rate['pay_type'],
            'pay_rate' => $rate['pay_rate'],
        ])->saveQuietly();

        $timesheet = $timesheet->fresh();
        $this->reconciler->reconcile($timesheet, $session);

        return $timesheet->fresh();
    }

    public function fromManualEntry(HrTimeEntry $entry, int $actorId): ?Timesheet
    {
        if (! $entry->clock_out) {
            return null;
        }

        $entry->loadMissing([
            'shift.client:id,first_name,last_name,site_id,service_context_id',
            'shift.site:id,name',
            'shift.serviceContext:id,name',
            'shift.staff:id,name',
            'client:id,first_name,last_name,site_id,service_context_id',
            'client.site:id,name',
            'client.serviceContext:id,name',
            'user:id,name',
        ]);

        $timesheet = Timesheet::query()
            ->where('hr_time_entry_id', $entry->id)
            ->first();

        if (! $timesheet && $entry->shift_id) {
            $timesheet = Timesheet::query()
                ->where('shift_id', $entry->shift_id)
                ->where('user_id', $entry->user_id)
                ->first();
        }

        if ($timesheet && $timesheet->exists && ! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return $timesheet->fresh();
        }

        $client = $entry->shift?->client ?? $entry->client;
        $snapshot = $entry->shift
            ? $this->snapshots->snapshotForShift($entry->shift, $entry->user)
            : $this->snapshots->snapshotForClient($client, $entry->user);

        $workDate = $entry->entry_date?->toDateString() ?? $entry->clock_in->toDateString();

        $payload = [
            'user_id' => $entry->user_id,
            'client_id' => $entry->shift?->client_id ?? $entry->client_id,
            'shift_id' => $entry->shift_id,
            'activity_type' => $entry->shift_id ? null : 'other',
            'site_id' => $entry->shift_id ? null : ($entry->site_id ?? $snapshot['site_id'] ?? null),
            'shift_site_id' => $snapshot['site_id'] ?? null,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? null,
            'work_date' => $workDate,
            'starts_at' => $entry->clock_in,
            'ends_at' => $entry->clock_out,
            'break_minutes' => (int) ($entry->break_minutes ?? 0),
            'mileage_km' => $entry->mileage_km,
            'sleepover' => (bool) $entry->is_sleepover,
            'on_call' => (bool) $entry->is_on_call,
            'public_holiday' => (bool) $entry->is_public_holiday || $this->isPublicHolidayFor(
                $workDate,
                $entry->site_id ?? $snapshot['site_id'] ?? null,
            ),
            'notes' => $entry->notes,
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? null,
            'shift_location_snapshot' => $snapshot['location'] ?? null,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? null,
            'client_name_snapshot' => $snapshot['client_name'] ?? null,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? null,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? null,
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? [],
            'status' => 'draft',
            'hr_time_entry_id' => $entry->id,
        ];

        if (! $timesheet) {
            Timesheet::ensureNoDuplicateShiftUserPair(
                $entry->shift_id ? (int) $entry->shift_id : null,
                (int) $entry->user_id,
            );

            $timesheet = Timesheet::query()->create([
                ...$payload,
                'created_by' => $actorId,
            ]);
        } else {
            $timesheet->update($payload);
            $timesheet = $timesheet->fresh();
        }

        $timesheet->loadMissing(['shift.site:id,name', 'shift.client:id,first_name,last_name,site_id', 'shift.serviceContext:id,name', 'user.hrEmployeeProfile', 'client:id,service_context_id']);
        $rate = $this->payrollRates->resolve($timesheet);
        $timesheet->forceFill([
            'pay_type' => $rate['pay_type'],
            'pay_rate' => $rate['pay_rate'],
            'payroll_segments_exported' => $rate['segments'],
        ])->saveQuietly();

        $timesheet = $timesheet->fresh();
        $this->reconciler->reconcile($timesheet);

        return $timesheet->fresh();
    }
}
