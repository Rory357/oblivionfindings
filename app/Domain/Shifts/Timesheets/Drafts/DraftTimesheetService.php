<?php

namespace App\Domain\Shifts\Timesheets\Drafts;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Shift;
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
    ) {
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

        $timesheet->fill([
            'user_id' => $shift->user_id,
            'client_id' => $shift->client_id,
            'shift_site_id' => $snapshot['site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $timesheet->shift_service_context_id,
            'attendance_session_id' => $timesheet->attendance_session_id ?: $uniqueAttendanceSession?->id,
            'work_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => (int) ($shift->expected_break_minutes ?? $timesheet->break_minutes ?? 0),
            'sleepover' => (bool) $shift->is_sleepover,
            'on_call' => (bool) $shift->is_on_call,
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
        if (! $clientId) {
            return null;
        }

        $snapshot = $session->shift
            ? $this->snapshots->snapshotForShift($session->shift, User::query()->find($session->user_id))
            : null;

        $payload = [
            'user_id' => $session->user_id,
            'client_id' => $clientId,
            'shift_id' => $session->shift_id,
            'shift_site_id' => $snapshot['site_id'] ?? null,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? null,
            'work_date' => $session->clock_in_at->toDateString(),
            'starts_at' => $session->clock_in_at,
            'ends_at' => $session->clock_out_at,
            'break_minutes' => (int) ($session->break_minutes ?? 0),
            'sleepover' => (bool) ($session->shift?->is_sleepover ?? false),
            'on_call' => (bool) ($session->shift?->is_on_call ?? false),
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
}
