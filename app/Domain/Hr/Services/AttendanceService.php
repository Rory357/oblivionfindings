<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\PayrollRateResolver;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftOperationalSnapshotService;
use App\Services\ShiftTimelineService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class AttendanceService
{
    public const AUTO_MATCH_GRACE_HOURS = 4;

    /**
     * @throws \LogicException
     */
    public function clockIn(User $user, array $data = []): HrAttendanceSession
    {
        $openSession = HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->first();

        if ($openSession) {
            throw new \LogicException('You are already clocked in. Please clock out before starting another session.');
        }

        $clockInAt = isset($data['clock_in_at']) ? Carbon::parse($data['clock_in_at']) : now();
        $shift = $this->resolveShift($user, $data, $clockInAt);
        $siteId = $data['site_id'] ?? $shift?->site_id ?? $shift?->client?->site_id;
        $shouldRecordShiftStart = $shift && in_array($shift->status, ['draft', 'scheduled'], true);

        $session = DB::transaction(function () use ($user, $data, $clockInAt, $shift, $siteId) {
            $session = HrAttendanceSession::create([
                'tenant_id' => $user->tenant_id ?? null,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'site_id' => $siteId,
                'clock_in_at' => $clockInAt,
                'status' => 'open',
                'source' => $data['source'] ?? 'manual',
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'meta' => $data['meta'] ?? null,
                'created_by' => $data['created_by'] ?? $user->id,
            ]);

            if ($shift && in_array($shift->status, ['draft', 'scheduled'], true)) {
                $shift->update([
                    'status' => 'in_progress',
                    'actual_starts_at' => $shift->actual_starts_at ?? $clockInAt,
                    'started_by' => $shift->started_by ?? $user->id,
                ]);
            }

            return $session->fresh(['shift.client', 'timesheet']);
        });

        if ($shouldRecordShiftStart && $session->shift) {
            app(ShiftTimelineService::class)->recordStarted(
                $session->shift->fresh(),
                $user,
                $session->clock_in_at,
            );
        }

        return $session->fresh(['shift.client', 'timesheet']);
    }

    /**
     * @throws \LogicException
     */
    public function clockOut(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $session ?: HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->latest('clock_in_at')
            ->first();

        if (! $session) {
            throw new \LogicException('No open attendance session found to clock out.');
        }

        if ((int) $session->user_id !== (int) $user->id) {
            throw new \LogicException('You can only clock out your own attendance session.');
        }

        $clockOutAt = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : now();
        if ($clockOutAt->lessThanOrEqualTo($session->clock_in_at)) {
            throw new \LogicException('Clock-out time must be after clock-in time.');
        }

        $breakMinutes = (int) ($data['break_minutes'] ?? $session->break_minutes ?? 0);
        if ($breakMinutes > 0) {
            $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($clockOutAt);
            if ($breakMinutes >= $elapsedMinutes) {
                throw new \LogicException(sprintf(
                    'Break duration (%d min) must be less than the session duration (%d min).',
                    $breakMinutes,
                    $elapsedMinutes,
                ));
            }
        }

        return DB::transaction(function () use ($session, $user, $clockOutAt, $data, $breakMinutes) {
            $session->update([
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'status' => 'closed',
                'notes' => $data['notes'] ?? $session->notes,
                'closed_by' => $user->id,
            ]);

            $this->syncTimesheetFromSession($session->fresh(['shift']), $user->id, $data);

            return $session->fresh(['shift.client', 'timesheet']);
        });
    }

    public function eligibleShiftsForUser(User $user, ?Carbon $clockInAt = null): Collection
    {
        $clockInAt = $clockInAt ?: now();

        return Shift::query()
            ->with('client:id,site_id')
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('starts_at', '<=', $clockInAt->copy()->addHours(self::AUTO_MATCH_GRACE_HOURS))
            ->where('ends_at', '>=', $clockInAt->copy()->subHours(self::AUTO_MATCH_GRACE_HOURS))
            ->orderBy('starts_at')
            ->get();
    }

    protected function resolveShift(User $user, array $data, Carbon $clockInAt): ?Shift
    {
        if (! empty($data['shift_id'])) {
            $shift = Shift::query()->with('client:id,site_id')->findOrFail($data['shift_id']);
            if ((int) $shift->user_id !== (int) $user->id) {
                throw new \LogicException('You cannot clock into a shift assigned to another staff member.');
            }

            if (! in_array($shift->status, ['draft', 'scheduled', 'in_progress'], true)) {
                throw new \LogicException('This shift cannot be clocked into in its current status.');
            }

            return $shift;
        }

        $eligibleShifts = $this->eligibleShiftsForUser($user, $clockInAt);

        if ($eligibleShifts->count() > 1) {
            throw new \LogicException('Multiple assigned shifts match this clock-in time. Please choose the shift you are starting.');
        }

        return $eligibleShifts->first();
    }

    protected function syncTimesheetFromSession(HrAttendanceSession $session, int $actorId, array $data = []): ?Timesheet
    {
        if (! $session->clock_out_at) {
            return null;
        }

        $reconciler = app(TimesheetReconciliationService::class);

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
            ? app(ShiftOperationalSnapshotService::class)->snapshotForShift($session->shift, User::query()->find($session->user_id))
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
            $reconciler->reconcile($timesheet->fresh(), $session);

            return $timesheet->fresh();
        }

        $timesheet->loadMissing(['shift.site:id,name', 'shift.client:id,first_name,last_name,site_id', 'shift.serviceContext:id,name', 'user.hrEmployeeProfile']);
        $rate = app(PayrollRateResolver::class)->resolve($timesheet);
        $timesheet->forceFill([
            'pay_type' => $rate['pay_type'],
            'pay_rate' => $rate['pay_rate'],
        ])->saveQuietly();

        $timesheet = $timesheet->fresh();
        $reconciler->reconcile($timesheet, $session);

        return $timesheet->fresh();
    }
}
