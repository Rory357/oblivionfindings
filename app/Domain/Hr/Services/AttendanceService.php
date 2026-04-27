<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\PayrollRateResolver;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftHandoverService;
use App\Services\ShiftOperationalSnapshotService;
use App\Services\ShiftTimelineService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        $blockers = $this->getEndOfShiftBlockers($session);
        $force = (bool) ($data['force'] ?? false);
        $overrideReason = trim((string) ($data['override_reason'] ?? ''));

        if ($blockers !== [] && ! $force) {
            throw new AttendanceClockOutBlockedException($blockers);
        }

        if ($blockers !== [] && $force && $overrideReason === '') {
            throw new \LogicException('An override reason is required to end a shift with outstanding checklist items.');
        }

        $clockOutAt = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : now();
        if ($clockOutAt->lessThanOrEqualTo($session->clock_in_at)) {
            throw new \LogicException('Clock-out time must be after clock-in time.');
        }

        $openBreakStartedAt = $session->break_started_at?->copy();
        $openBreakMinutes = $openBreakStartedAt
            ? max(0, (int) $openBreakStartedAt->diffInMinutes($clockOutAt))
            : 0;
        $breakMinutes = max(
            (int) ($data['break_minutes'] ?? $session->break_minutes ?? 0),
            (int) ($session->break_minutes ?? 0) + $openBreakMinutes,
        );
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

        $closedSession = DB::transaction(function () use ($session, $user, $clockOutAt, $data, $breakMinutes, $blockers, $force, $overrideReason, $openBreakStartedAt) {
            $session->update([
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'notes' => $data['notes'] ?? $session->notes,
                'closed_by' => $user->id,
            ]);

            $this->closeOpenBreakEvent($session, $clockOutAt, $openBreakStartedAt);

            if ($session->shift && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                $session->shift->update([
                    'status' => 'completed',
                    'actual_ends_at' => $session->shift->actual_ends_at ?? $clockOutAt,
                    'completed_by' => $session->shift->completed_by ?? $user->id,
                ]);
            }

            $this->syncTimesheetFromSession($session->fresh(['shift']), $user->id, $data);

            if ($blockers !== [] && $force) {
                AuditLogger::log('attendance.clockOut.forced', $session, [
                    'attendance_session_id' => $session->id,
                    'shift_id' => $session->shift_id,
                    'override_reason' => $overrideReason,
                    'blockers' => $blockers,
                ]);
            }

            return $session->fresh(['shift.client', 'timesheet']);
        });

        if ($closedSession->shift) {
            app(ShiftTimelineService::class)->recordCompleted(
                $closedSession->shift->fresh(),
                $user,
                $closedSession->clock_out_at,
                $blockers !== [] && $force ? ['forced_clock_out' => true] : [],
            );
        }

        return $closedSession;
    }

    public function eligibleShiftsForUser(User $user, ?Carbon $clockInAt = null): Collection
    {
        $clockInAt = ($clockInAt ?: now())->copy()->utc();

        return Shift::query()
            ->with('client:id,site_id')
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('starts_at', '<=', $clockInAt->copy()->addHours(self::AUTO_MATCH_GRACE_HOURS))
            ->where('ends_at', '>=', $clockInAt->copy()->subHours(self::AUTO_MATCH_GRACE_HOURS))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEndOfShiftBlockers(HrAttendanceSession $session): array
    {
        $session->loadMissing([
            'shift.tasks',
            'shift.outgoingHandovers',
        ]);

        $shift = $session->shift;
        if (! $shift) {
            return [];
        }

        $blockers = [];

        $pendingTasks = $shift->tasks
            ->where('is_completed', false)
            ->count();

        if ($pendingTasks > 0) {
            $blockers[] = [
                'key' => 'tasks_pending',
                'label' => 'Finish shift tasks',
                'detail' => trans_choice('{1} 1 shift task is still open.|[2,*] :count shift tasks are still open.', $pendingTasks),
                'count' => $pendingTasks,
                'action_url' => '#shift-tasks',
                'blocking' => true,
            ];
        }

        $handoverSubmitted = $shift->outgoingHandovers->contains(
            fn (ShiftHandover $handover) => in_array($handover->status, [
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ], true),
        );

        if (! $handoverSubmitted && ! $shift->handover_waived_at) {
            $blockers[] = [
                'key' => 'handover_missing',
                'label' => 'Write handover',
                'detail' => 'The next shift still needs a handover.',
                'count' => 1,
                'action_url' => '#handover',
                'blocking' => true,
            ];
        }

        $draftIncidents = ClientIncident::query()
            ->where('shift_id', $shift->id)
            ->where('reported_by', $session->user_id)
            ->where(function ($query) {
                $query->where('status', 'draft')
                    ->orWhereNull('submitted_at');
            })
            ->count();

        if ($draftIncidents > 0) {
            $blockers[] = [
                'key' => 'incidents_draft',
                'label' => 'Submit draft incidents',
                'detail' => trans_choice('{1} 1 incident report is still a draft.|[2,*] :count incident reports are still drafts.', $draftIncidents),
                'count' => $draftIncidents,
                'action_url' => '/incidents?shift_id='.$shift->id,
                'blocking' => true,
            ];
        }

        $unsignedMeds = $this->countUnsignedMedicationDoses($shift);
        if ($unsignedMeds > 0) {
            $blockers[] = [
                'key' => 'meds_unsigned',
                'label' => 'Sign medication records',
                'detail' => trans_choice('{1} 1 scheduled medication still needs a MAR entry.|[2,*] :count scheduled medications still need MAR entries.', $unsignedMeds),
                'count' => $unsignedMeds,
                'action_url' => $shift->client_id ? '/meds/today?client_id='.$shift->client_id : '/meds/today',
                'blocking' => true,
            ];
        }

        return $blockers;
    }

    /**
     * @throws \LogicException
     */
    public function startBreak(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $this->resolveOpenSessionForUser($user, $session);

        if ($session->break_started_at) {
            throw new \LogicException('A break is already in progress.');
        }

        $startedAt = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();

        return DB::transaction(function () use ($session, $user, $startedAt) {
            $session->update([
                'break_started_at' => $startedAt,
                'break_count' => ((int) $session->break_count) + 1,
            ]);

            HrAttendanceBreakEvent::query()->create([
                'session_id' => $session->id,
                'started_at' => $startedAt,
                'created_by' => $user->id,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'breakEvents']);
        });
    }

    /**
     * @throws \LogicException
     */
    public function endBreak(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $this->resolveOpenSessionForUser($user, $session);

        if (! $session->break_started_at) {
            throw new \LogicException('No break is currently in progress.');
        }

        $endedAt = isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : now();
        if ($endedAt->lessThan($session->break_started_at)) {
            throw new \LogicException('Break end time must be after break start time.');
        }

        $minutes = max(0, (int) $session->break_started_at->diffInMinutes($endedAt));

        return DB::transaction(function () use ($session, $endedAt, $minutes) {
            $event = HrAttendanceBreakEvent::query()
                ->where('session_id', $session->id)
                ->whereNull('ended_at')
                ->latest('started_at')
                ->first();

            if ($event) {
                $event->update([
                    'ended_at' => $endedAt,
                    'minutes' => $minutes,
                ]);
            }

            $session->update([
                'break_started_at' => null,
                'break_minutes' => ((int) $session->break_minutes) + $minutes,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'breakEvents']);
        });
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

    protected function resolveOpenSessionForUser(User $user, ?HrAttendanceSession $session = null): HrAttendanceSession
    {
        $session = $session ?: HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->latest('clock_in_at')
            ->first();

        if (! $session) {
            throw new \LogicException('No open attendance session found.');
        }

        if ((int) $session->user_id !== (int) $user->id) {
            throw new \LogicException('You can only update your own attendance session.');
        }

        return $session;
    }

    protected function closeOpenBreakEvent(HrAttendanceSession $session, Carbon $clockOutAt, ?Carbon $startedAt): void
    {
        HrAttendanceBreakEvent::query()
            ->where('session_id', $session->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first()
            ?->update([
                'ended_at' => $clockOutAt,
                'minutes' => $startedAt
                    ? max(0, (int) $startedAt->diffInMinutes($clockOutAt))
                    : null,
            ]);
    }

    protected function countUnsignedMedicationDoses(Shift $shift): int
    {
        if (! $shift->client_id || ! $shift->starts_at || ! $shift->ends_at) {
            return 0;
        }

        $medications = ClientMedication::query()
            ->where('client_id', $shift->client_id)
            ->active()
            ->where('is_prn', false)
            ->whereNotNull('dose_times')
            ->get(['id', 'dose_times']);

        if ($medications->isEmpty()) {
            return 0;
        }

        $start = $shift->starts_at->copy();
        $end = $shift->ends_at->copy();

        $signedKeys = ClientMedicationAdministration::query()
            ->where('client_id', $shift->client_id)
            ->whereIn('status', ['given', 'refused', 'withheld', 'missed'])
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [
                $start->copy()->utc(),
                $end->copy()->utc(),
            ])
            ->get(['client_medication_id', 'scheduled_for'])
            ->mapWithKeys(fn (ClientMedicationAdministration $administration) => [
                $administration->client_medication_id.'|'.$administration->scheduled_for?->copy()->utc()->format('Y-m-d H:i') => true,
            ]);

        $unsigned = 0;

        foreach ($medications as $medication) {
            $doseTimes = is_array($medication->dose_times) ? $medication->dose_times : [];
            if ($doseTimes === []) {
                continue;
            }

            $day = $start->copy()->startOfDay();
            $lastDay = $end->copy()->startOfDay();

            while ($day->lessThanOrEqualTo($lastDay)) {
                foreach ($doseTimes as $doseTime) {
                    $scheduled = $day->copy()->setTimeFromTimeString($doseTime);

                    if (! $scheduled->betweenIncluded($start, $end)) {
                        continue;
                    }

                    $key = $medication->id.'|'.$scheduled->copy()->utc()->format('Y-m-d H:i');
                    if (! $signedKeys->has($key)) {
                        $unsigned++;
                    }
                }

                $day->addDay();
            }
        }

        return $unsigned;
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
