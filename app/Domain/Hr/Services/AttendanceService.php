<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarScheduleService;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public const AUTO_MATCH_GRACE_HOURS = 4;

    /**
     * @throws \LogicException
     */
    public function clockIn(User $user, array $data = []): HrAttendanceSession
    {
        $clockInAt = isset($data['clock_in_at']) ? Carbon::parse($data['clock_in_at']) : now();
        $shift = $this->resolveShift($user, $data, $clockInAt);
        $siteId = $data['site_id'] ?? $shift?->site_id ?? $shift?->client?->site_id;

        $session = DB::transaction(function () use ($user, $data, $clockInAt, $shift, $siteId) {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $openSession = HrAttendanceSession::query()
                ->where('user_id', $user->id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($openSession) {
                throw new \LogicException('You are already clocked in. Please clock out before starting another session.');
            }

            $session = HrAttendanceSession::create([
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
                app(ShiftLifecycleService::class)->start(
                    $shift,
                    $user,
                    $clockInAt,
                    ShiftLifecycleSource::ClockIn,
                );
            }

            return $session->fresh(['shift.client', 'timesheet']);
        });

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

        $force = (bool) ($data['force'] ?? false);
        $overrideReason = trim((string) ($data['override_reason'] ?? ''));

        $clockOutAt = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : now();

        $closedSession = DB::transaction(function () use ($session, $user, $clockOutAt, $data, $force, $overrideReason) {
            $session = HrAttendanceSession::query()
                ->with(['shift'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ((int) $session->user_id !== (int) $user->id) {
                throw new \LogicException('You can only clock out your own attendance session.');
            }

            if ($session->status !== 'open' || $session->clock_out_at) {
                throw new \LogicException('This attendance session has already been closed.');
            }

            if ($clockOutAt->lessThanOrEqualTo($session->clock_in_at)) {
                throw new \LogicException('Clock-out time must be after clock-in time.');
            }

            // Workers can adjust the clock-out time (wizard time field), but
            // never into the future — sessions record what already happened.
            if ($clockOutAt->gt(now()->addMinutes(2))) {
                throw new \LogicException('Clock-out time cannot be in the future.');
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

            $this->applyTaskUpdates($session, $user, $data['task_updates'] ?? []);
            $this->saveHandoverFromClockOut($session, $user, $data['handover'] ?? null);

            $session = $session->fresh([
                'shift.tasks',
                'shift.outgoingHandovers' => fn ($query) => app(UserSiteAccessService::class)
                    ->applyHandoverIntegrityScope($query->getQuery()),
            ]) ?? $session;

            $blockers = $this->getEndOfShiftBlockers($session);

            if ($blockers !== [] && ! $force) {
                throw new AttendanceClockOutBlockedException($blockers);
            }

            if (
                $blockers !== []
                && $force
                && $this->hasClinicalClockOutBlockers($blockers)
                && ! $this->canForceClinicalClockOut($user)
            ) {
                throw new \LogicException('Clinical blockers need a manager override before this shift can be force clocked out.');
            }

            if ($blockers !== [] && $force && $overrideReason === '') {
                throw new \LogicException('An override reason is required to end a shift with outstanding checklist items.');
            }

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
                app(ShiftLifecycleService::class)->complete(
                    $session->shift,
                    $user,
                    CompleteShiftData::fromClockOutSession(
                        $session->fresh(['shift']) ?? $session,
                        $force,
                        $overrideReason,
                        $blockers !== [] && $force ? ['forced_clock_out' => true] : [],
                    ),
                );
            }

            app(DraftTimesheetService::class)->fromAttendanceSession($session->fresh(['shift']), $user->id, $data);

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

        // Safety overlay: end any lone-worker monitoring tied to this shift now
        // that the worker has clocked out (outside the transaction — it must
        // never be able to roll back or block the clock-out itself).
        $this->completeLinkedLoneWorkerSessions($closedSession, $user);

        return $closedSession;
    }

    /**
     * Auto-end Lone Worker Safety monitoring when a monitored shift is clocked
     * out. A worker who safely finishes their shift shouldn't have to also end
     * the session, and — more importantly — leaving it live would let the
     * 5-minute overdue job (CheckLoneWorkerOverdueJob) later flip the now-idle
     * session to "overdue" and raise a false Control Room alert after the worker
     * is already safely off shift.
     *
     * Deliberately narrow and safety-conscious:
     *  - only sessions linked to THE shift being clocked out (its `shift_id`),
     *    for this worker — never ad-hoc sessions (no shift link), which a
     *    coordinator ends explicitly;
     *  - only `active` / `overdue` sessions. An `emergency` session is an
     *    unresolved safety event that must be resolved deliberately via the
     *    Control Room — never silently cleared by a routine clock-out.
     *
     * Fail-soft: a problem here must never block the clock-out itself.
     */
    private function completeLinkedLoneWorkerSessions(HrAttendanceSession $session, User $user): void
    {
        $shiftId = $session->shift_id;
        if (! $shiftId) {
            return;
        }

        try {
            LoneWorkerSession::query()
                ->where('shift_id', $shiftId)
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'overdue'])
                ->get()
                // Per-model update (not a mass update) so model events and the
                // AuditableChanges trail fire for each ended session.
                ->each(fn (LoneWorkerSession $loneWorkerSession) => $loneWorkerSession->update([
                    'ended_at' => now(),
                    'status' => 'completed',
                    'updated_by' => $user->id,
                ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Force-close another user's open session — the manager action behind the
     * "On the clock now" board (missed clock-outs, wrong clock-ins). Skips the
     * end-of-shift checklist blockers (the worker isn't here to answer them)
     * but records who closed it, why, and an audit-log row.
     *
     * Caller is responsible for authorization (timesheets.manageAny — the same
     * permission that gates the board itself).
     *
     * @throws \LogicException
     */
    public function adminEndSession(User $admin, HrAttendanceSession $session, string $reason, ?Carbon $endAt = null): HrAttendanceSession
    {
        return DB::transaction(function () use ($admin, $session, $reason, $endAt) {
            $session = HrAttendanceSession::query()
                ->with(['shift'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($session->status !== 'open' || $session->clock_out_at) {
                throw new \LogicException('This attendance session has already been closed.');
            }

            $wasStale = $session->clock_in_at !== null
                && $session->clock_in_at->lt(now()->subHours(16));

            // Close at the rostered end when it has already passed (accurate
            // hours for abandoned sessions); otherwise close now. Never before
            // clock-in — the safety invariant requires a positive duration.
            $shiftEnd = $session->shift?->ends_at;
            $endAt = $endAt
                ?? ($shiftEnd && $shiftEnd->gt($session->clock_in_at) && $shiftEnd->lte(now())
                    ? $shiftEnd->copy()
                    : now());
            if ($endAt->lessThanOrEqualTo($session->clock_in_at)) {
                $endAt = $session->clock_in_at->copy()->addMinute();
            }

            // Accumulate any open break, but clamp below the elapsed time — a
            // days-old open break would otherwise violate the session invariant
            // and make the stuck session unclosable (the exact case this
            // action exists for).
            $openBreakStartedAt = $session->break_started_at?->copy();
            $openBreakMinutes = $openBreakStartedAt
                ? max(0, (int) $openBreakStartedAt->diffInMinutes($endAt))
                : 0;
            $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($endAt);
            $breakMinutes = min(
                (int) ($session->break_minutes ?? 0) + $openBreakMinutes,
                max($elapsedMinutes - 1, 0),
            );

            $session->update([
                'clock_out_at' => $endAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'closed_by' => $admin->id,
                'meta' => array_merge((array) $session->meta, [
                    'admin_ended' => true,
                    'admin_end_reason' => $reason,
                ]),
            ]);

            $this->closeOpenBreakEvent($session, $endAt, $openBreakStartedAt);

            if ($session->shift && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                app(ShiftLifecycleService::class)->complete(
                    $session->shift,
                    $admin,
                    CompleteShiftData::fromClockOutSession(
                        $session->fresh(['shift']) ?? $session,
                        true,
                        $reason,
                        ['admin_ended' => true],
                    ),
                );
            }

            // Same principle as clockOut: shift or no shift, their time is
            // logged — the draft stays editable/archivable by the team.
            app(DraftTimesheetService::class)->fromAttendanceSession($session->fresh(['shift']), $admin->id, []);

            AuditLogger::log('attendance.session.adminEnded', $session, [
                'attendance_session_id' => $session->id,
                'session_user_id' => $session->user_id,
                'shift_id' => $session->shift_id,
                'reason' => $reason,
                'clock_out_at' => $endAt->toDateTimeString(),
                'was_stale' => $wasStale,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'user']);
        });
    }

    /**
     * Correct a session's clock-out time and breaks — the "fix a missed
     * clock-out" wizard. Works on open sessions (closes them at the corrected
     * time) and already-closed ones (rewrites the times). Always carries a
     * reason into the audit log; a submitted timesheet returns to draft so the
     * corrected hours go back through approval.
     *
     * Caller is responsible for authorization (timesheets.manageAny, or the
     * worker correcting their own session).
     *
     * @throws \LogicException
     */
    public function correctSession(User $actor, HrAttendanceSession $session, Carbon $clockOutAt, int $breakMinutes, string $reason): HrAttendanceSession
    {
        return DB::transaction(function () use ($actor, $session, $clockOutAt, $breakMinutes, $reason) {
            $session = HrAttendanceSession::query()
                ->with(['shift'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if (! $session->clock_in_at) {
                throw new \LogicException('This session has no clock-in time to correct against.');
            }

            if ($clockOutAt->lessThanOrEqualTo($session->clock_in_at)) {
                throw new \LogicException('Clock-out time must be after clock-in time.');
            }

            if ($clockOutAt->gt(now()->addMinutes(2))) {
                throw new \LogicException('Clock-out time cannot be in the future.');
            }

            $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($clockOutAt);
            if ($breakMinutes > 0 && $breakMinutes >= $elapsedMinutes) {
                throw new \LogicException(sprintf(
                    'Break duration (%d min) must be less than the session duration (%d min).',
                    $breakMinutes,
                    $elapsedMinutes,
                ));
            }

            // Same timesheet lookup as the draft sync: by session link first,
            // then by the shift/user pair.
            $timesheet = Timesheet::query()
                ->where('attendance_session_id', $session->id)
                ->first();
            if (! $timesheet && $session->shift_id) {
                $timesheet = Timesheet::query()
                    ->where('shift_id', $session->shift_id)
                    ->where('user_id', $session->user_id)
                    ->first();
            }

            if ($timesheet && $timesheet->status === 'approved') {
                throw new \LogicException('The linked timesheet has already been approved — adjust the hours through a timesheet amendment instead.');
            }

            $wasOpen = $session->status === 'open' && ! $session->clock_out_at;
            $openBreakStartedAt = $session->break_started_at?->copy();
            $before = [
                'clock_out_at' => optional($session->clock_out_at)->toDateTimeString(),
                'break_minutes' => (int) $session->break_minutes,
                'status' => $session->status,
            ];

            $session->update([
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'closed_by' => $wasOpen ? $actor->id : ($session->closed_by ?? $actor->id),
                'meta' => array_merge((array) $session->meta, [
                    'corrected' => true,
                    'correction_reason' => $reason,
                ]),
            ]);

            if ($wasOpen) {
                $this->closeOpenBreakEvent($session, $clockOutAt, $openBreakStartedAt);

                if ($session->shift && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                    app(ShiftLifecycleService::class)->complete(
                        $session->shift,
                        $actor,
                        CompleteShiftData::fromClockOutSession(
                            $session->fresh(['shift']) ?? $session,
                            true,
                            $reason,
                            ['corrected' => true],
                        ),
                    );
                }
            }

            // A submitted timesheet returns to draft so fromAttendanceSession
            // rewrites its hours and the approval starts over on real numbers.
            if ($timesheet && $timesheet->status === 'submitted') {
                $timesheet->update(['status' => 'draft']);
            }

            app(DraftTimesheetService::class)->fromAttendanceSession($session->fresh(['shift']), $actor->id, []);

            AuditLogger::log('attendance.session.corrected', $session, [
                'attendance_session_id' => $session->id,
                'session_user_id' => $session->user_id,
                'shift_id' => $session->shift_id,
                'reason' => $reason,
                'was_open' => $wasOpen,
                'before' => $before,
                'after' => [
                    'clock_out_at' => $clockOutAt->toDateTimeString(),
                    'break_minutes' => $breakMinutes,
                    'status' => 'closed',
                ],
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'user']);
        });
    }

    public function eligibleShiftsForUser(User $user, ?Carbon $clockInAt = null): Collection
    {
        $clockInAt = ($clockInAt ?: now())->copy()->utc();

        return Shift::query()
            ->with('client:id,site_id')
            ->where('user_id', $user->id)
            ->visibleToFrontline()
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
            'shift.outgoingHandovers' => fn ($query) => app(UserSiteAccessService::class)
                ->applyHandoverIntegrityScope($query->getQuery()),
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
     * @param  array<int, array<string, mixed>>  $blockers
     */
    protected function hasClinicalClockOutBlockers(array $blockers): bool
    {
        $clinicalKeys = ['incidents_draft', 'meds_unsigned'];

        foreach ($blockers as $blocker) {
            if (in_array((string) ($blocker['key'] ?? ''), $clinicalKeys, true)) {
                return true;
            }
        }

        return false;
    }

    protected function canForceClinicalClockOut(User $user): bool
    {
        return $user->canDo('shifts.manageAny')
            || $user->canDo('timesheets.manageAny')
            || $user->canDo('clients.update');
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
                throw new \LogicException($this->clockInBlockedMessageFor((string) $shift->status));
            }

            return $shift;
        }

        $eligibleShifts = $this->eligibleShiftsForUser($user, $clockInAt);

        if ($eligibleShifts->count() > 1) {
            throw new \LogicException('Multiple assigned shifts match this clock-in time. Please choose the shift you are starting.');
        }

        return $eligibleShifts->first();
    }

    protected function clockInBlockedMessageFor(string $status): string
    {
        return match ($status) {
            'completed' => 'This shift has already been completed. Ask your manager to create a timesheet amendment if you need to adjust your hours.',
            'cancelled' => 'This shift was cancelled and cannot be clocked into. Ask your manager if it should be reinstated.',
            default => "This shift cannot be clocked into in its current status ({$status}).",
        };
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

    /**
     * @param  array<int, array{id?: int, is_completed?: bool}>|mixed  $updates
     */
    protected function applyTaskUpdates(HrAttendanceSession $session, User $user, mixed $updates): void
    {
        if (! is_array($updates) || $updates === []) {
            return;
        }

        if (! $session->shift_id) {
            throw ValidationException::withMessages([
                'task_updates' => 'Task updates require a linked shift.',
            ]);
        }

        $normalized = collect($updates)
            ->map(fn (mixed $update) => is_array($update) ? [
                'id' => (int) ($update['id'] ?? 0),
                'is_completed' => (bool) ($update['is_completed'] ?? false),
            ] : null)
            ->filter(fn (?array $update) => $update !== null && $update['id'] > 0)
            ->values();

        if ($normalized->isEmpty()) {
            return;
        }

        $ids = $normalized->pluck('id')->unique()->values();

        if ($ids->count() !== $normalized->count()) {
            throw ValidationException::withMessages([
                'task_updates' => 'Task updates included the same task more than once.',
            ]);
        }

        $tasks = ShiftTask::query()
            ->where('shift_id', $session->shift_id)
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($tasks->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'task_updates' => 'One or more shift tasks could no longer be found. Refresh and try again.',
            ]);
        }

        foreach ($normalized as $update) {
            /** @var ShiftTask $task */
            $task = $tasks->get($update['id']);
            $completed = (bool) $update['is_completed'];

            $task->forceFill([
                'is_completed' => $completed,
                'completed_at' => $completed ? ($task->completed_at ?? now()) : null,
                'completed_by' => $completed ? ($task->completed_by ?? $user->id) : null,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>|mixed  $handover
     */
    protected function saveHandoverFromClockOut(HrAttendanceSession $session, User $user, mixed $handover): void
    {
        if (! is_array($handover) || $handover === [] || ! $session->shift_id) {
            return;
        }

        $shift = $session->shift ?: Shift::query()->find($session->shift_id);
        if (! $shift) {
            return;
        }

        $alreadySubmitted = ShiftHandover::query()
            ->tap(fn ($query) => app(UserSiteAccessService::class)->applyHandoverIntegrityScope($query))
            ->where('outgoing_shift_id', $shift->id)
            ->whereIn('status', [
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ])
            ->exists();

        if ($alreadySubmitted) {
            return;
        }

        $notes = trim((string) ($handover['handover_notes'] ?? ''));
        $medsCompleted = (bool) ($handover['meds_completed'] ?? true);
        $followUpNeeded = (bool) ($handover['follow_up_needed'] ?? false);

        if ($notes === '') {
            $notes = $medsCompleted
                ? 'No specific items to flag for the next shift.'
                : 'Medications were not fully completed - please review on arrival.';
        }

        // Optional task list from the clock-out wizard — discrete items the
        // incoming shift must action, same shape the Shift Handover wizard files.
        $tasksPending = collect(is_array($handover['tasks_pending'] ?? null) ? $handover['tasks_pending'] : [])
            ->map(fn ($task) => trim((string) $task))
            ->filter(fn ($task) => $task !== '')
            ->map(fn ($task) => ['label' => $task])
            ->values()
            ->all();

        app(ShiftHandoverService::class)->save($shift, $user, [
            'handover_notes' => $notes,
            'client_mood' => $handover['shift_rating'] ?? null,
            'medications_due' => $medsCompleted
                ? null
                : [[
                    'label' => 'Review outstanding medications from previous shift',
                    'severity' => 'high',
                ]],
            'follow_up_items' => $followUpNeeded
                ? [[
                    'label' => 'Follow-up flagged by outgoing worker',
                    'priority' => 'medium',
                ]]
                : null,
            'tasks_pending' => $tasksPending !== [] ? $tasksPending : null,
            'submit' => true,
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
            ->where(function ($query) {
                $query->whereNotNull('dose_times')
                    ->orWhereNotNull('frequency');
            })
            ->get(['id', 'dose_times', 'frequency', 'active', 'is_prn', 'start_date', 'end_date']);

        if ($medications->isEmpty()) {
            return 0;
        }

        $scheduleService = app(MarScheduleService::class);
        $start = $shift->starts_at->copy()->timezone($scheduleService->workerTimezone());
        $end = $shift->ends_at->copy()->timezone($scheduleService->workerTimezone());

        $signedKeys = ClientMedicationAdministration::query()
            ->where('client_id', $shift->client_id)
            ->whereIn('status', ['given', 'refused', 'withheld', 'missed'])
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [
                $start->copy()->utc(),
                $end->copy()->utc(),
            ])
            ->get(['client_medication_id', 'scheduled_for'])
            ->mapWithKeys(function (ClientMedicationAdministration $administration) {
                $rawScheduledFor = $administration->getRawOriginal('scheduled_for');
                $scheduledKey = $rawScheduledFor
                    ? Carbon::parse((string) $rawScheduledFor, 'UTC')->format('Y-m-d H:i')
                    : '';

                return [$administration->client_medication_id.'|'.$scheduledKey => true];
            });

        $unsigned = 0;

        foreach ($medications as $medication) {
            $day = $start->copy()->startOfDay();
            $lastDay = $end->copy()->startOfDay();

            while ($day->lessThanOrEqualTo($lastDay)) {
                foreach ($scheduleService->scheduledTimesForDate($medication, $day) as $scheduled) {
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
}
