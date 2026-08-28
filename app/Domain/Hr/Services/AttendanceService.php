<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Enums\AttendanceTimesheetSyncOutcome;
use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\Client;
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
use App\Services\AuthorizationEvidenceLockService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public const AUTO_MATCH_GRACE_HOURS = 4;

    /** @var array<int, string> */
    private const COMMAND_AUTHORIZATION_EVIDENCE = [
        'timesheets.manageAny',
        'reports.viewAny',
        'hr.time.manage',
        'timesheets.approve',
        'hr.time.approveTeam',
        'timesheets.create',
        'shifts.viewAssigned',
        'shifts.update',
        'shifts.manageAny',
        'clients.update',
        'medications.controlled.view',
        'medications.controlled.record',
    ];

    public function __construct(
        private readonly AttendanceTimeEntryProjector $timeEntries,
        private readonly AuthorizationEvidenceLockService $authorizationEvidence,
        private readonly MedicationGovernanceScopeService $medicationGovernance,
    ) {}

    /**
     * @throws \LogicException
     */
    public function clockIn(User $user, array $data = []): HrAttendanceSession
    {
        $clockInAt = isset($data['clock_in_at']) ? Carbon::parse($data['clock_in_at']) : now();
        $shift = $this->resolveShift($user, $data, $clockInAt);
        $requestedSiteId = $data['site_id'] ?? null;

        $session = DB::transaction(function () use ($user, $data, $clockInAt, $shift, $requestedSiteId) {
            $this->timeEntries->lockApplicationPayrollMutex();

            // Participate in the same Client -> Shift mutex used by completion.
            // Otherwise completion can observe no attendance rows while this
            // transaction concurrently inserts a new open session.
            $lockedShift = $shift
                ? app(ShiftHandoverService::class)->lockCompletionShift($shift)
                : null;

            if ($lockedShift) {
                if ((int) $lockedShift->user_id !== (int) $user->id) {
                    throw new \LogicException('You cannot clock into a shift assigned to another staff member.');
                }

                if (! in_array($lockedShift->status, ['draft', 'scheduled', 'in_progress'], true)) {
                    throw new \LogicException($this->clockInBlockedMessageFor((string) $lockedShift->status));
                }
            }

            $lockedUser = $this->lockCurrentSelfAttendanceActor($user);
            if ($lockedShift && (int) $lockedShift->user_id !== (int) $lockedUser->id) {
                throw new \LogicException('You cannot clock into a shift assigned to another staff member.');
            }

            $siteId = $lockedShift
                ? $this->canonicalSelfAttendanceShiftSiteId($lockedShift, $lockedUser)
                : $this->lockShiftlessClockInSiteId($lockedUser, $requestedSiteId);
            if ($requestedSiteId !== null && $this->positiveId($requestedSiteId) !== $siteId) {
                throw new \LogicException('Attendance Site provenance conflicts with the canonical worker assignment.');
            }

            $lockedTimesheet = null;
            if ($lockedShift) {
                $timesheets = Timesheet::query()
                    ->where('shift_id', $lockedShift->id)
                    ->where('user_id', $lockedUser->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($timesheets->count() > 1) {
                    throw new \LogicException('Attendance is linked to conflicting Timesheets.');
                }
                $lockedTimesheet = $timesheets->first();
            }

            $this->timeEntries->assertNoWorkerTimeOverlap(
                (int) $lockedUser->id,
                $clockInAt,
                null,
            );

            $openSession = HrAttendanceSession::query()
                ->where('user_id', $lockedUser->id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($openSession) {
                throw new \LogicException('You are already clocked in. Please clock out before starting another session.');
            }

            $session = HrAttendanceSession::create([
                'user_id' => $lockedUser->id,
                'shift_id' => $lockedShift?->id,
                'site_id' => $siteId,
                'clock_in_at' => $clockInAt,
                'status' => 'open',
                'source' => $data['source'] ?? 'manual',
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'meta' => $data['meta'] ?? null,
                'created_by' => $data['created_by'] ?? $lockedUser->id,
            ]);
            $session->setRelation('shift', $lockedShift);

            if ($lockedShift && in_array($lockedShift->status, ['draft', 'scheduled'], true)) {
                app(ShiftLifecycleService::class)->start(
                    $lockedShift,
                    $lockedUser,
                    $clockInAt,
                    ShiftLifecycleSource::ClockIn,
                );
            }

            $this->timeEntries->lockPayrollRunsForSession($session);
            $this->timeEntries->project($session, $lockedUser, [
                ...$data,
                'locked_timesheet' => $lockedTimesheet,
                'payroll_boundary_locked' => true,
                'link_timesheet' => true,
            ]);

            return $session->fresh(['shift.client', 'timesheet']);
        });

        return $session->fresh(['shift.client', 'timesheet']);
    }

    /**
     * Compatibility projection for legacy/backfill callers. Re-read and lock
     * the canonical aggregate; never project from the caller's stale model.
     */
    public function projectTimeEntryForSession(
        User $actor,
        HrAttendanceSession|int $session,
        array $extra = [],
    ): HrTimeEntry {
        $sessionId = $session instanceof HrAttendanceSession ? (int) $session->id : $session;

        return DB::transaction(function () use ($actor, $sessionId, $extra): HrTimeEntry {
            $this->timeEntries->lockApplicationPayrollMutex();
            [$session, $actor, $lockedWorker] = $this->lockCurrentAttendanceCommand($actor, $sessionId);
            $this->assertValidProjectionInterval($session);
            try {
                $siteId = $this->attendanceSessionSiteIdForMutation($session, false);
            } catch (\LogicException) {
                abort(404);
            }

            if ($session->site_id === null) {
                $session->update(['site_id' => $siteId]);
            }

            $this->timeEntries->lockPayrollRunsForSession($session);
            $lockedTimesheet = $this->lockAttendanceTimesheet($session);
            $lockedEntry = $this->timeEntries->assertMutableProjection($session, $lockedTimesheet, true);
            $this->timeEntries->assertNoWorkerTimeOverlap(
                (int) $lockedWorker->id,
                $session->clock_in_at,
                $session->clock_out_at,
                $lockedEntry?->id,
            );

            return $this->timeEntries->project($session, $actor, [
                ...$extra,
                'locked_timesheet' => $lockedTimesheet,
                'payroll_boundary_locked' => true,
                'link_timesheet' => true,
            ]);
        });
    }

    protected function assertValidProjectionInterval(HrAttendanceSession $session): void
    {
        if (! $session->clock_in_at) {
            throw new \LogicException('Attendance-backed time entries require a clock-in time.');
        }

        $breakMinutes = (int) ($session->break_minutes ?? 0);
        if ($breakMinutes < 0) {
            throw new \LogicException('Break duration cannot be negative.');
        }

        if (! $session->clock_out_at) {
            return;
        }

        if ($session->clock_out_at->lessThanOrEqualTo($session->clock_in_at)) {
            throw new \LogicException('Clock-out time must be after clock-in time.');
        }

        $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($session->clock_out_at);
        if ($breakMinutes >= $elapsedMinutes) {
            throw new \LogicException(sprintf(
                'Break duration (%d min) must be less than the session duration (%d min).',
                $breakMinutes,
                $elapsedMinutes,
            ));
        }
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

        abort_unless((int) $session->user_id === (int) $user->id, 404);
        $sessionId = (int) $session->id;

        $force = (bool) ($data['force'] ?? false);
        $overrideReason = trim((string) ($data['override_reason'] ?? ''));

        $clockOutAt = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : now();

        $closedSession = DB::transaction(function () use ($sessionId, $user, $clockOutAt, $data, $force, $overrideReason) {
            $this->timeEntries->lockApplicationPayrollMutex();

            // A clock-out handover may introduce incoming and controlled-drug
            // witness Shifts/Users. Acquire that complete aggregate first;
            // the later attendance lock is then a re-entrant subset of the
            // already-owned Shift/User graph. Any later clock-out rejection
            // rolls the nested handover, audit, and timeline writes back with
            // this outer transaction.
            $sessionSnapshot = HrAttendanceSession::query()
                ->whereKey($sessionId)
                ->first(['id', 'user_id', 'shift_id']);
            abort_unless($sessionSnapshot instanceof HrAttendanceSession, 404);
            abort_unless((int) $sessionSnapshot->user_id === (int) $user->id, 404);
            $this->saveHandoverFromClockOut(
                $sessionSnapshot,
                $user,
                $data['handover'] ?? null,
                [(int) $sessionSnapshot->user_id],
            );

            [$session, $lockedUser] = $this->lockCurrentAttendanceCommand($user, $sessionId);
            abort_unless($this->canCorrectOwnSession($lockedUser), 403);

            abort_unless((int) $session->user_id === (int) $lockedUser->id, 404);

            $siteId = $this->assertSelfAttendanceSessionScope($session, $lockedUser);

            if ($session->status !== 'open' || $session->clock_out_at) {
                throw new \LogicException('This attendance session has already been closed.');
            }

            $this->assertClockOutPayloadScope($session, $data);

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

            // Validate and lock the complete projection boundary before any
            // checklist, handover, session, Shift, or Timesheet write. Legacy
            // sessions without an HrTimeEntry must not close into an existing
            // payable worker interval, and protected projection evidence must
            // leave the entire clock-out command unchanged.
            $session->forceFill([
                'site_id' => $session->site_id ?: $siteId,
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
            ]);
            $this->assertValidProjectionInterval($session);
            $this->timeEntries->lockPayrollRunsForSession($session);
            $lockedTimesheet = $this->lockAttendanceTimesheet($session);
            $lockedEntry = $this->timeEntries->assertMutableProjection($session, $lockedTimesheet, true);
            $this->timeEntries->assertNoWorkerTimeOverlap(
                (int) $lockedUser->id,
                $session->clock_in_at,
                $clockOutAt,
                $lockedEntry?->id,
            );

            $this->applyTaskUpdates($session, $lockedUser, $data['task_updates'] ?? []);

            $session->shift?->load([
                'tasks',
                'outgoingHandovers' => fn ($query) => app(UserSiteAccessService::class)
                    ->applyHandoverIntegrityScope($query->getQuery()),
            ]);

            $blockers = $this->getEndOfShiftBlockers($session);

            if ($blockers !== [] && ! $force) {
                throw new AttendanceClockOutBlockedException($blockers);
            }

            if (
                $blockers !== []
                && $force
                && $this->hasClinicalClockOutBlockers($blockers)
                && ! $this->canForceClinicalClockOut($lockedUser)
            ) {
                throw new \LogicException('Clinical blockers need a manager override before this shift can be force clocked out.');
            }

            if ($blockers !== [] && $force && $overrideReason === '') {
                throw new \LogicException('An override reason is required to end a shift with outstanding checklist items.');
            }

            $session->update([
                'site_id' => $session->site_id ?: $siteId,
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'notes' => $data['notes'] ?? $session->notes,
                'closed_by' => $lockedUser->id,
            ]);

            $this->closeOpenBreakEvent($session, $clockOutAt, $openBreakStartedAt);

            if ($this->canCompleteLinkedShift($session)
                && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                app(ShiftLifecycleService::class)->complete(
                    $session->shift,
                    $lockedUser,
                    CompleteShiftData::fromClockOutSession(
                        $session,
                        $force,
                        $overrideReason,
                        $blockers !== [] && $force ? ['forced_clock_out' => true] : [],
                    ),
                );
            }

            $timesheetSyncOutcome = $this->syncAttendanceTimesheet(
                $session,
                $lockedUser->id,
                $data,
                $lockedTimesheet,
            );

            $this->timeEntries->project($session, $lockedUser, [
                ...$data,
                'locked_timesheet' => $lockedTimesheet,
                'payroll_boundary_locked' => true,
                'link_timesheet' => $timesheetSyncOutcome !== AttendanceTimesheetSyncOutcome::SkippedFollowUp,
            ]);

            if ($timesheetSyncOutcome === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
                AuditLogger::logOrFail('attendance.clockOut.payrollFollowUp', $session, [
                    'actor_id' => $lockedUser->id,
                    'attendance_session_id' => $session->id,
                    'shift_id' => $session->shift_id,
                    'timesheet_id' => $lockedTimesheet?->id,
                    'timesheet_sync_outcome' => $timesheetSyncOutcome->value,
                ]);
            }

            if ($blockers !== [] && $force) {
                AuditLogger::logOrFail('attendance.clockOut.forced', $session, [
                    'actor_id' => $lockedUser->id,
                    'attendance_session_id' => $session->id,
                    'shift_id' => $session->shift_id,
                    'override_reason' => $overrideReason,
                    'blockers' => $blockers,
                    'timesheet_sync_outcome' => $timesheetSyncOutcome->value,
                ]);
            }

            return ($session->fresh(['shift.client', 'timesheet']) ?? $session)
                ->markTimesheetSyncOutcome($timesheetSyncOutcome);
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
     * The command owns both the timesheets.manageAny action check and the
     * canonical attendance Site boundary. The controller repeats the action
     * check only to fail before request validation.
     *
     * @throws \LogicException
     */
    public function adminEndSession(User $admin, HrAttendanceSession $session, string $reason, ?Carbon $endAt = null): HrAttendanceSession
    {
        abort_unless($admin->canDo('timesheets.manageAny'), 403);
        $sessionId = (int) $session->id;

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Please provide a reason for ending this session.',
            ]);
        }

        return DB::transaction(function () use ($admin, $sessionId, $reason, $endAt) {
            $this->timeEntries->lockApplicationPayrollMutex();
            [$session, $lockedAdmin] = $this->lockCurrentAttendanceCommand($admin, $sessionId);
            abort_unless($lockedAdmin->canDo('timesheets.manageAny'), 404);
            try {
                $siteId = $this->attendanceSessionSiteIdForMutation($session, false);
            } catch (\LogicException) {
                abort(404);
            }
            abort_unless(
                $this->currentAttendanceActorHasSiteAccess($lockedAdmin, $siteId, true),
                404,
            );

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

            // Establish the final interval in memory, then lock and validate
            // all payable projection evidence before closing breaks, changing
            // the session/Shift, syncing a Timesheet, or writing audit rows.
            $session->forceFill([
                'site_id' => $session->site_id ?: $siteId,
                'clock_out_at' => $endAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
            ]);
            $this->assertValidProjectionInterval($session);
            $this->timeEntries->lockPayrollRunsForSession($session);
            $lockedTimesheet = $this->lockAttendanceTimesheet($session);
            $lockedEntry = $this->timeEntries->assertMutableProjection($session, $lockedTimesheet, true);
            $this->timeEntries->assertNoWorkerTimeOverlap(
                (int) $session->user_id,
                $session->clock_in_at,
                $endAt,
                $lockedEntry?->id,
            );

            $session->update([
                // Preserve the resolved Site on legacy rows so downstream
                // timesheet/audit provenance no longer follows a mutable profile.
                'site_id' => $session->site_id ?: $siteId,
                'clock_out_at' => $endAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'closed_by' => $lockedAdmin->id,
                'meta' => array_merge((array) $session->meta, [
                    'admin_ended' => true,
                    'admin_end_reason' => $reason,
                ]),
            ]);

            $this->closeOpenBreakEvent($session, $endAt, $openBreakStartedAt);

            if ($this->canCompleteLinkedShift($session)
                && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                app(ShiftLifecycleService::class)->complete(
                    $session->shift,
                    $lockedAdmin,
                    CompleteShiftData::fromClockOutSession(
                        $session,
                        true,
                        $reason,
                        ['admin_ended' => true],
                    ),
                );
            }

            // Reassigned/cancelled legacy attendance remains recoverable, but
            // its existing Timesheet is immutable here. The explicit outcome
            // keeps the controller and audit honest about payroll follow-up.
            $timesheetSyncOutcome = $this->syncAttendanceTimesheet(
                $session,
                $lockedAdmin->id,
                [],
                $lockedTimesheet,
            );

            $this->timeEntries->project($session, $lockedAdmin, [
                'amendment_reason' => $reason,
                'locked_timesheet' => $lockedTimesheet,
                'payroll_boundary_locked' => true,
                'link_timesheet' => $timesheetSyncOutcome !== AttendanceTimesheetSyncOutcome::SkippedFollowUp,
            ]);

            AuditLogger::logOrFail('attendance.session.adminEnded', $session, [
                'actor_id' => $lockedAdmin->id,
                'attendance_session_id' => $session->id,
                'session_user_id' => $session->user_id,
                'shift_id' => $session->shift_id,
                'site_id' => $siteId,
                'reason' => $reason,
                'clock_out_at' => $endAt->toDateTimeString(),
                'was_stale' => $wasStale,
                'timesheet_sync_outcome' => $timesheetSyncOutcome->value,
            ]);

            return ($session->fresh(['shift.client', 'timesheet', 'user']) ?? $session)
                ->markTimesheetSyncOutcome($timesheetSyncOutcome);
        });
    }

    /**
     * Correct a session's clock-out time and breaks — the "fix a missed
     * clock-out" wizard. Works on open sessions (closes them at the corrected
     * time) and already-closed ones (rewrites the times). Always carries a
     * reason into the audit log; a submitted timesheet returns to draft so the
     * corrected hours go back through approval.
     *
     * This command owns both correction authority and the canonical attendance
     * Site boundary. The controller resolves once before request validation;
     * the command repeats the same decision under the aggregate lock.
     *
     * @throws \LogicException
     */
    public function correctSession(User $actor, HrAttendanceSession|int $session, Carbon $clockOutAt, int $breakMinutes, string $reason): HrAttendanceSession
    {
        $sessionId = $session instanceof HrAttendanceSession ? (int) $session->id : $session;
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Please provide a reason for correcting this session.',
            ]);
        }

        return DB::transaction(function () use ($actor, $sessionId, $clockOutAt, $breakMinutes, $reason) {
            $this->timeEntries->lockApplicationPayrollMutex();
            [$session, $lockedActor, $lockedTarget] = $this->lockCurrentAttendanceCommand($actor, $sessionId);
            $canManage = $lockedActor->canDo('timesheets.manageAny');
            $canApprove = $lockedActor->canDo('timesheets.approve');
            $canCorrectSelf = $this->canCorrectOwnSession($lockedActor);
            $isSelf = (int) $lockedTarget->id === (int) $lockedActor->id;
            $targetProfile = $lockedTarget->hrEmployeeProfile;
            abort_unless(
                ($isSelf && $canCorrectSelf)
                    || ($canManage)
                    || ($canApprove
                        && $targetProfile instanceof HrEmployeeProfile
                        && (int) ($targetProfile->manager_user_id ?? 0) === (int) $lockedActor->id),
                404,
            );
            try {
                $siteId = $this->attendanceSessionSiteIdForMutation(
                    $session,
                    ! $canManage,
                );
            } catch (\LogicException) {
                abort(404);
            }
            abort_unless(
                $this->currentAttendanceActorHasSiteAccess($lockedActor, $siteId, $canManage)
                    && $targetProfile instanceof HrEmployeeProfile
                    && $this->attendanceProfileHasSite($targetProfile, $siteId),
                404,
            );

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
            if ($breakMinutes < 0) {
                throw new \LogicException('Break duration cannot be negative.');
            }
            if ($breakMinutes >= $elapsedMinutes) {
                throw new \LogicException(sprintf(
                    'Break duration (%d min) must be less than the session duration (%d min).',
                    $breakMinutes,
                    $elapsedMinutes,
                ));
            }

            // Serialize correction with approval before changing attendance.
            // Reassigned/cancelled recovery is attendance-only and leaves every
            // existing Timesheet byte-for-byte unchanged.
            $canSyncTimesheet = $this->canSyncAttendanceTimesheet($session);
            $this->timeEntries->lockPayrollRunsForSession($session);
            $timesheet = $this->lockAttendanceTimesheet($session);
            if ($session->site_id === null) {
                // Projector preflight needs canonical provenance, but the
                // historical row is persisted only after every payroll guard
                // succeeds so a denied correction remains side-effect free.
                $session->site_id = $siteId;
            }
            $lockedEntry = $this->timeEntries->assertMutableProjection($session, $timesheet, true);
            $this->timeEntries->assertNoWorkerTimeOverlap(
                (int) $session->user_id,
                $session->clock_in_at,
                $clockOutAt,
                $lockedEntry?->id,
            );

            $wasOpen = $session->status === 'open' && ! $session->clock_out_at;
            $openBreakStartedAt = $session->break_started_at?->copy();
            $before = [
                'clock_out_at' => optional($session->clock_out_at)->toDateTimeString(),
                'break_minutes' => (int) $session->break_minutes,
                'status' => $session->status,
            ];

            $session->update([
                'site_id' => $session->site_id ?: $siteId,
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'closed_by' => $wasOpen ? $lockedActor->id : ($session->closed_by ?? $lockedActor->id),
                'meta' => array_merge((array) $session->meta, [
                    'corrected' => true,
                    'correction_reason' => $reason,
                ]),
            ]);

            if ($wasOpen) {
                $this->closeOpenBreakEvent($session, $clockOutAt, $openBreakStartedAt);

                if ($this->canCompleteLinkedShift($session)
                    && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                    app(ShiftLifecycleService::class)->complete(
                        $session->shift,
                        $lockedActor,
                        CompleteShiftData::fromClockOutSession(
                            $session,
                            true,
                            $reason,
                            ['corrected' => true],
                        ),
                    );
                }
            }

            // A submitted timesheet returns to draft so fromAttendanceSession
            // rewrites its hours and the approval starts over on real numbers.
            if ($canSyncTimesheet && $timesheet?->status === 'submitted') {
                $timesheet->update(['status' => 'draft']);
            }

            $timesheetSyncOutcome = $this->syncAttendanceTimesheet(
                $session,
                $lockedActor->id,
                [],
                $timesheet,
                $canSyncTimesheet,
            );

            $this->timeEntries->project($session, $lockedActor, [
                'amendment_reason' => $reason,
                'locked_timesheet' => $timesheet,
                'payroll_boundary_locked' => true,
                'link_timesheet' => $timesheetSyncOutcome !== AttendanceTimesheetSyncOutcome::SkippedFollowUp,
            ]);

            AuditLogger::logOrFail('attendance.session.corrected', $session, [
                'actor_id' => $lockedActor->id,
                'attendance_session_id' => $session->id,
                'session_user_id' => $session->user_id,
                'shift_id' => $session->shift_id,
                'reason' => $reason,
                'was_open' => $wasOpen,
                'timesheet_sync_outcome' => $timesheetSyncOutcome->value,
                'before' => $before,
                'after' => [
                    'clock_out_at' => $clockOutAt->toDateTimeString(),
                    'break_minutes' => $breakMinutes,
                    'status' => 'closed',
                ],
            ]);

            return ($session->fresh(['shift.client', 'timesheet', 'user']) ?? $session)
                ->markTimesheetSyncOutcome($timesheetSyncOutcome);
        });
    }

    /**
     * Resolve a correction target without disclosing foreign direct objects.
     * Managers need the action permission and canonical Site access; workers
     * may resolve only their own intrinsically valid session.
     */
    protected function lockCurrentCommandActor(User $actor): User
    {
        $lockedActor = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->whereKey($actor->id)
            ->lockForUpdate()
            ->first();
        abort_unless($lockedActor, 404);

        $lockedActor = $this->authorizationEvidence->lockForUser(
            $lockedActor,
            self::COMMAND_AUTHORIZATION_EVIDENCE,
        );
        $profile = $this->lockCurrentAttendanceWorkerProfile((int) $lockedActor->id);
        $lockedActor->setRelation('hrEmployeeProfile', $profile);

        return $lockedActor;
    }

    protected function lockCurrentSelfAttendanceActor(User $actor): User
    {
        $lockedActor = $this->lockCurrentCommandActor($actor);
        abort_unless($this->canCorrectOwnSession($lockedActor), 403);

        return $lockedActor;
    }

    protected function canonicalSelfAttendanceShiftSiteId(Shift $shift, User $worker): int
    {
        $shift->loadMissing('client:id,site_id');
        $shiftSiteId = $this->positiveId($shift->site_id);
        $clientSiteId = $this->positiveId($shift->client?->site_id);
        abort_unless(
            $shiftSiteId !== null
            && $clientSiteId === $shiftSiteId,
            404,
        );
        try {
            $shiftSiteId = app(UserSiteAccessService::class)->activeAttendanceSiteId($shiftSiteId);
        } catch (\LogicException) {
            abort(404);
        }

        $profile = $worker->hrEmployeeProfile;
        abort_unless(
            $profile instanceof HrEmployeeProfile
            && $this->attendanceProfileHasSite($profile, $shiftSiteId),
            404,
        );

        return $shiftSiteId;
    }

    protected function assertSelfAttendanceSessionScope(HrAttendanceSession $session, User $worker): int
    {
        $session->setRelation('user', $worker);
        try {
            $siteId = $this->attendanceSessionSiteIdForMutation($session, true);
        } catch (\LogicException) {
            abort(404);
        }

        $profile = $worker->hrEmployeeProfile;
        abort_unless(
            $profile instanceof HrEmployeeProfile
            && $this->attendanceProfileHasSite($profile, $siteId),
            404,
        );

        return $siteId;
    }

    protected function lockCurrentAttendanceWorkerProfile(int $userId): HrEmployeeProfile
    {
        $user = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->whereKey($userId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($user, 404);

        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $profiles = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($dates) use ($today): void {
                $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($dates) use ($today): void {
                $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        abort_unless($profiles->count() === 1, 404);

        return $profiles->first();
    }

    public function resolveCorrectableSession(User $actor, int $sessionId, bool $lockForUpdate = false): HrAttendanceSession
    {
        abort_unless($sessionId > 0, 404);

        $lockedTarget = null;
        $lockedSession = null;
        if ($lockForUpdate) {
            [$lockedSession, $actor, $lockedTarget] = $this->lockCurrentAttendanceCommand($actor, $sessionId);
        }

        if ($actor->canDo('timesheets.manageAny')) {
            $session = $lockForUpdate
                ? $lockedSession
                : $this->resolveManageableSession($actor, $sessionId, false);
            $targetProfile = $lockForUpdate
                ? $lockedTarget?->hrEmployeeProfile
                : $session->user?->hrEmployeeProfile;
            abort_unless($targetProfile && $this->isCurrentAttendanceProfile($targetProfile), 404);
            try {
                $siteId = $this->attendanceSessionSiteIdForMutation($session, false);
            } catch (\LogicException) {
                abort(404);
            }
            abort_unless($this->attendanceProfileHasSite($targetProfile, $siteId), 404);

            return $session;
        }

        $canApprove = $actor->canDo('timesheets.approve');
        $canCorrectSelf = $this->canCorrectOwnSession($actor);
        abort_unless($canApprove || $canCorrectSelf, $lockForUpdate ? 404 : 403);

        if ($lockForUpdate) {
            $session = $lockedSession;
        } else {
            $session = HrAttendanceSession::query()
                ->with([
                    'shift.client:id,site_id',
                    'user:id',
                    'user.hrEmployeeProfile',
                ])
                ->whereKey($sessionId)
                ->firstOrFail();
        }

        $targetProfile = $lockForUpdate
            ? $lockedTarget?->hrEmployeeProfile
            : $session->user?->hrEmployeeProfile;
        abort_unless($targetProfile && $this->isCurrentAttendanceProfile($targetProfile), 404);

        $isSelf = (int) $session->user_id === (int) $actor->id;
        abort_unless(
            ($isSelf && $canCorrectSelf)
                || ($canApprove && (int) ($targetProfile->manager_user_id ?? 0) === (int) $actor->id),
            404,
        );

        try {
            $siteId = $this->attendanceSessionSiteIdForMutation($session, true);
        } catch (\LogicException) {
            abort(404);
        }
        abort_unless(
            in_array(
                $siteId,
                // Correction authority never widens the actor's approved Site
                // boundary. Use a fresh resolver on both the controller pass
                // and the later locked command pass so cached scope evidence
                // cannot authorize a foreign direct report.
                (new UserSiteAccessService)->accessibleSiteIds($actor, []),
                true,
            ),
            404,
        );
        abort_unless($this->attendanceProfileHasSite($targetProfile, $siteId), 404);

        return $session;
    }

    protected function isCurrentAttendanceProfile(HrEmployeeProfile $profile): bool
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return (bool) $profile->is_active
            && ($profile->start_date === null || $profile->start_date->toDateString() <= $today)
            && ($profile->end_date === null || $profile->end_date->toDateString() >= $today);
    }

    protected function attendanceProfileHasSite(HrEmployeeProfile $profile, int $siteId): bool
    {
        return collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $assignedSiteId): bool => is_numeric($assignedSiteId))
            ->map(fn (mixed $assignedSiteId): int => (int) $assignedSiteId)
            ->contains($siteId);
    }

    protected function currentAttendanceActorHasSiteAccess(
        User $actor,
        int $siteId,
        bool $allowGlobalBypass,
    ): bool {
        return $actor->hrEmployeeProfile instanceof HrEmployeeProfile
            && (
                ($allowGlobalBypass
                    && app(UserSiteAccessService::class)->canBypass(
                        $actor,
                        UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
                    ))
                || $this->attendanceProfileHasSite($actor->hrEmployeeProfile, $siteId)
            );
    }

    /**
     * Resolve a manager attendance target through canonical Site provenance.
     * A historical session remains manageable after its Shift is reassigned;
     * assignment integrity is enforced separately before Shift completion.
     */
    public function resolveManageableSession(User $actor, int $sessionId, bool $lockForUpdate = false): HrAttendanceSession
    {
        abort_unless($actor->canDo('timesheets.manageAny'), 403);
        abort_unless($sessionId > 0, 404);

        if ($lockForUpdate) {
            [$session, $actor] = $this->lockCurrentAttendanceCommand($actor, $sessionId);
        } else {
            $session = HrAttendanceSession::query()
                ->with([
                    'shift.client:id,site_id',
                    'user:id',
                    'user.hrEmployeeProfile',
                ])
                ->whereKey($sessionId)
                ->firstOrFail();
        }

        try {
            $siteId = $this->attendanceSessionSiteIdForMutation($session, false);
        } catch (\LogicException) {
            abort(404);
        }

        abort_unless(
            in_array(
                $siteId,
                // The controller performs an unlocked concealment check first.
                // A fresh resolver on each pass prevents the later locked
                // command from reusing Site membership cached by that check.
                (new UserSiteAccessService)->accessibleSiteIds(
                    $actor,
                    UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
                ),
                true,
            ),
            404,
        );

        return $session;
    }

    /**
     * Lock a mutating attendance command in the cross-domain order shared by
     * eMAR, handover, and Shift completion: Client -> Shift -> complete sorted
     * User/RBAC set -> current Profiles -> active Site -> attendance session.
     * The unlocked session/Shift reads below are identity hints only.
     *
     * @return array{0: HrAttendanceSession, 1: User, 2: User}
     */
    protected function lockCurrentAttendanceCommand(
        User $actor,
        int $sessionId,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Attendance commands must be locked inside a transaction.');
        }

        $sessionSnapshot = HrAttendanceSession::query()
            ->whereKey($sessionId)
            ->first(['id', 'user_id', 'shift_id', 'site_id']);
        abort_unless(
            $sessionSnapshot !== null
                && is_numeric($sessionSnapshot->user_id)
                && (int) $sessionSnapshot->user_id > 0,
            404,
        );

        $lockedShift = null;
        if ($sessionSnapshot->shift_id !== null) {
            $shiftSnapshot = Shift::query()
                ->whereKey($sessionSnapshot->shift_id)
                ->first(['id', 'client_id']);
            abort_unless(
                $shiftSnapshot !== null
                    && is_numeric($shiftSnapshot->client_id)
                    && (int) $shiftSnapshot->client_id > 0,
                404,
            );
            $lockedClient = Client::query()
                ->whereKey((int) $shiftSnapshot->client_id)
                ->lockForUpdate()
                ->first(['id', 'site_id']);
            abort_unless($lockedClient !== null, 404);
            $lockedShift = Shift::query()
                ->whereKey($shiftSnapshot->id)
                ->where('client_id', $lockedClient->id)
                ->lockForUpdate()
                ->first();
            abort_unless($lockedShift !== null, 404);
            $lockedShift->setRelation('client', $lockedClient);
        }

        $targetUserId = (int) $sessionSnapshot->user_id;
        $lockedUsers = $this->authorizationEvidence->lockForUsers(
            [(int) $actor->id, $targetUserId],
            self::COMMAND_AUTHORIZATION_EVIDENCE,
        );
        $lockedActor = $lockedUsers->get((int) $actor->id);
        $lockedTarget = $lockedUsers->get($targetUserId);
        abort_unless($lockedActor instanceof User && $lockedTarget instanceof User, 404);
        $profiles = $this->medicationGovernance->lockCurrentStaffProfiles(
            $lockedUsers,
            collect([(int) $lockedActor->id, $targetUserId])->unique()->sort()->values()->all(),
        );
        $lockedUsers->each(function (User $lockedUser) use ($profiles): void {
            $lockedUser->setRelation('hrEmployeeProfile', $profiles->get((int) $lockedUser->id));
        });
        $targetProfile = $profiles->get($targetUserId);
        abort_unless($targetProfile instanceof HrEmployeeProfile, 404);

        if ($lockedShift instanceof Shift) {
            $canonicalSiteIds = collect([
                $this->positiveId($lockedShift->site_id),
                $this->positiveId($lockedShift->client?->site_id),
            ])->filter()->unique()->values();
            abort_unless($canonicalSiteIds->count() === 1, 404);
            $siteId = (int) $canonicalSiteIds->first();
            $capturedSiteId = $this->positiveId($sessionSnapshot->site_id);
            abort_unless($capturedSiteId === null || $capturedSiteId === $siteId, 404);
        } else {
            $capturedSiteId = $this->positiveId($sessionSnapshot->site_id);
            $primarySiteId = $this->positiveId($targetProfile->primary_site_id);
            abort_unless(
                $capturedSiteId !== null || $primarySiteId !== null,
                404,
            );
            $siteId = $capturedSiteId ?? $primarySiteId;
        }
        app(UserSiteAccessService::class)->activeAttendanceSiteId($siteId);

        $session = HrAttendanceSession::query()
            ->whereKey($sessionSnapshot->id)
            ->where('user_id', $targetUserId)
            ->when(
                $lockedShift === null,
                fn ($query) => $query->whereNull('shift_id'),
                fn ($query) => $query->where('shift_id', $lockedShift->id),
            )
            ->lockForUpdate()
            ->first();
        abort_unless($session instanceof HrAttendanceSession, 404);
        $session->setRelation('user', $lockedTarget);
        $session->setRelation('shift', $lockedShift);

        return [$session, $lockedActor, $lockedTarget];
    }

    /**
     * Resolve canonical Site provenance for a locked attendance mutation.
     * Manager repair paths deliberately tolerate a later Shift reassignment;
     * worker mutations require the session worker to remain the assignee.
     */
    protected function attendanceSessionSiteIdForMutation(
        HrAttendanceSession $session,
        bool $requireCurrentShiftAssignee,
    ): int {
        $session->loadMissing([
            'shift.client:id,site_id',
            'user:id',
            'user.hrEmployeeProfile',
        ]);

        if (! $session->user) {
            throw new \LogicException('Attendance-session worker provenance is missing.');
        }
        if ($session->shift_id !== null && ! $session->shift) {
            throw new \LogicException('Attendance-session Shift provenance is missing.');
        }

        if (! $session->shift) {
            return app(UserSiteAccessService::class)->attendanceSessionSiteId($session);
        }

        if ($requireCurrentShiftAssignee
            && (int) ($session->shift->user_id ?? 0) !== (int) $session->user_id) {
            throw new \LogicException('Attendance-session Shift worker provenance conflicts.');
        }
        if ($session->shift->client_id !== null && ! $session->shift->client) {
            throw new \LogicException('Attendance-session Shift Client provenance is missing.');
        }

        $capturedSiteId = $this->positiveId($session->site_id);
        $shiftSiteIds = collect([
            $this->positiveId($session->shift->site_id),
            $this->positiveId($session->shift->client?->site_id),
        ])->filter()->unique()->values();
        if ($shiftSiteIds->count() !== 1) {
            throw new \LogicException('Attendance-session Shift Site provenance is missing or conflicting.');
        }

        $siteId = (int) $shiftSiteIds->first();
        if ($capturedSiteId !== null && $capturedSiteId !== $siteId) {
            throw new \LogicException('Attendance-session captured Site conflicts with its Shift.');
        }

        return app(UserSiteAccessService::class)->activeAttendanceSiteId($siteId);
    }

    protected function canCompleteLinkedShift(HrAttendanceSession $session): bool
    {
        return $session->shift !== null
            && (int) ($session->shift->user_id ?? 0) > 0
            && (int) $session->shift->user_id === (int) $session->user_id;
    }

    protected function canSyncAttendanceTimesheet(HrAttendanceSession $session): bool
    {
        return $session->shift_id === null
            || ($this->canCompleteLinkedShift($session) && $session->shift->status !== 'cancelled');
    }

    protected function lockAttendanceTimesheet(HrAttendanceSession $session): ?Timesheet
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Attendance Timesheets must be locked inside a transaction.');
        }

        $entryId = HrTimeEntry::withTrashed()
            ->where('attendance_session_id', $session->id)
            ->value('id');
        $timesheets = Timesheet::query()
            ->where(function ($query) use ($session, $entryId): void {
                $query->where('attendance_session_id', $session->id);
                if ($entryId) {
                    $query->orWhere('hr_time_entry_id', $entryId);
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
        if ($timesheets->count() > 1) {
            throw new \LogicException('Attendance is linked to conflicting Timesheets.');
        }

        return $timesheets->first();
    }

    protected function syncAttendanceTimesheet(
        HrAttendanceSession $session,
        int $actorId,
        array $data,
        ?Timesheet $lockedTimesheet,
        ?bool $canSync = null,
    ): AttendanceTimesheetSyncOutcome {
        if (! ($canSync ?? $this->canSyncAttendanceTimesheet($session))) {
            return AttendanceTimesheetSyncOutcome::SkippedFollowUp;
        }

        // Submitted/approved/payroll-owned rows are immutable on operational
        // attendance paths. Do not even reconcile them here: follow-up must be
        // explicit and the protected row remains byte-for-byte unchanged.
        if ($lockedTimesheet && ! in_array($lockedTimesheet->status, ['draft', 'returned'], true)) {
            return AttendanceTimesheetSyncOutcome::SkippedFollowUp;
        }

        $existingStatus = $lockedTimesheet?->status;
        $timesheet = app(DraftTimesheetService::class)->fromAttendanceSession(
            $session,
            $actorId,
            $data,
            $lockedTimesheet,
            true,
        );

        if (! $timesheet) {
            return AttendanceTimesheetSyncOutcome::None;
        }
        if (! $lockedTimesheet) {
            return AttendanceTimesheetSyncOutcome::Created;
        }

        return in_array($existingStatus, ['draft', 'returned'], true)
            ? AttendanceTimesheetSyncOutcome::Updated
            : AttendanceTimesheetSyncOutcome::SkippedFollowUp;
    }

    protected function lockShiftlessClockInSiteId(User $user, mixed $requestedSiteId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Shiftless attendance Site provenance must be locked inside a transaction.');
        }

        $profile = $user->hrEmployeeProfile;

        $siteId = $this->positiveId($profile?->primary_site_id);
        if ($siteId === null) {
            throw new \LogicException('Shiftless attendance requires a current primary Site.');
        }

        if ($requestedSiteId !== null && $this->positiveId($requestedSiteId) !== $siteId) {
            throw new \LogicException('Shiftless attendance Site provenance conflicts with the worker profile.');
        }

        try {
            return app(UserSiteAccessService::class)->activeAttendanceSiteId($siteId);
        } catch (\LogicException) {
            throw new \LogicException('Shiftless attendance Site provenance is unavailable.');
        }
    }

    protected function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function eligibleShiftsForUser(User $user, ?Carbon $clockInAt = null, ?User $viewer = null): Collection
    {
        $clockInAt = ($clockInAt ?: now())->copy()->utc();

        return Shift::query()
            ->when($viewer, fn ($query) => app(UserSiteAccessService::class)->applyShiftScope(
                $query,
                $viewer,
                UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
            ))
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
     * Resolve an explicitly selected self-clock Shift without exposing whether
     * an unassigned ID exists. This is a read-only concealment pass; clockIn()
     * repeats every ownership/profile/Site decision after taking its mutexes.
     */
    public function resolveSelfClockInShift(User $user, mixed $shiftId): ?Shift
    {
        if ($shiftId === null || $shiftId === '') {
            return null;
        }

        $shiftId = $this->positiveId($shiftId);
        abort_unless($shiftId !== null, 404);

        $shift = Shift::query()
            ->with('client:id,site_id')
            ->whereKey($shiftId)
            ->where('user_id', $user->id)
            ->first();
        abort_unless($shift !== null, 404);

        $worker = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->whereKey($user->id)
            ->first(['id']);
        abort_unless($worker !== null, 404);
        $worker->setRelation('hrEmployeeProfile', $this->currentAttendanceProfileForRead((int) $worker->id));
        $this->canonicalSelfAttendanceShiftSiteId($shift, $worker);

        return $shift;
    }

    /**
     * Resolve an explicitly selected self-attendance session before validating
     * nested clock-out/break payloads. Foreign, missing and corrupt aggregates
     * intentionally collapse to the same not-found response.
     */
    public function resolveSelfAttendanceSession(User $user, mixed $sessionId): ?HrAttendanceSession
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        $sessionId = $this->positiveId($sessionId);
        abort_unless($sessionId !== null, 404);

        $session = HrAttendanceSession::query()
            ->with(['shift.client:id,site_id', 'user:id'])
            ->whereKey($sessionId)
            ->where('user_id', $user->id)
            ->first();
        abort_unless($session !== null && $session->user !== null, 404);

        $profile = $this->currentAttendanceProfileForRead((int) $user->id);
        $session->user->setRelation('hrEmployeeProfile', $profile);

        try {
            $siteId = $this->attendanceSessionSiteIdForMutation($session, true);
        } catch (\LogicException) {
            abort(404);
        }
        abort_unless($this->attendanceProfileHasSite($profile, $siteId), 404);

        return $session;
    }

    protected function currentAttendanceProfileForRead(int $userId): HrEmployeeProfile
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $profiles = HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where(function ($query) use ($today): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->orderBy('id')
            ->get();
        abort_unless($profiles->count() === 1, 404);

        return $profiles->first();
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

        // Capturing the handover satisfies the clock-out checklist. It may
        // remain a draft until the outgoing worker reviews and binds the exact
        // incoming Shift; submission is deliberately not inferred here.
        $handoverCaptured = $shift->outgoingHandovers->contains(
            fn (ShiftHandover $handover) => in_array($handover->status, [
                ShiftHandoverService::STATUS_DRAFT,
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ], true),
        );

        if (! $handoverCaptured && ! $shift->handover_waived_at) {
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

    protected function canCorrectOwnSession(User $user): bool
    {
        return $user->canDo('timesheets.create')
            || $user->canDo('shifts.viewAssigned')
            || $user->canDo('shifts.update')
            || $user->canDo('shifts.manageAny');
    }

    /**
     * @throws \LogicException
     */
    public function startBreak(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $startedAt = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();
        $sessionId = $session?->id;

        return DB::transaction(function () use ($sessionId, $user, $startedAt) {
            $this->timeEntries->lockApplicationPayrollMutex();
            [$session, $lockedUser] = $this->lockOpenSessionForUser(
                $user,
                $sessionId ? (int) $sessionId : null,
            );
            $this->assertSelfAttendanceSessionScope($session, $lockedUser);
            $openEvents = HrAttendanceBreakEvent::query()
                ->where('session_id', $session->id)
                ->whereNull('ended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($session->break_started_at || $openEvents->isNotEmpty()) {
                throw new \LogicException('A break is already in progress.');
            }

            $session->update([
                'break_started_at' => $startedAt,
                'break_count' => ((int) $session->break_count) + 1,
            ]);

            HrAttendanceBreakEvent::query()->create([
                'session_id' => $session->id,
                'started_at' => $startedAt,
                'created_by' => $lockedUser->id,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'breakEvents']);
        });
    }

    /**
     * @throws \LogicException
     */
    public function endBreak(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $endedAt = isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : now();
        $sessionId = $session?->id;

        return DB::transaction(function () use ($sessionId, $user, $endedAt) {
            $this->timeEntries->lockApplicationPayrollMutex();
            [$session, $lockedUser] = $this->lockOpenSessionForUser(
                $user,
                $sessionId ? (int) $sessionId : null,
            );
            $this->assertSelfAttendanceSessionScope($session, $lockedUser);
            if (! $session->break_started_at) {
                throw new \LogicException('No break is currently in progress.');
            }
            if ($endedAt->lessThan($session->break_started_at)) {
                throw new \LogicException('Break end time must be after break start time.');
            }

            $events = HrAttendanceBreakEvent::query()
                ->where('session_id', $session->id)
                ->whereNull('ended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($events->count() !== 1) {
                throw new \LogicException('The active attendance break evidence is missing or conflicting.');
            }
            $minutes = max(0, (int) $session->break_started_at->diffInMinutes($endedAt));
            $events->first()->update([
                'ended_at' => $endedAt,
                'minutes' => $minutes,
            ]);

            $session->update([
                'break_started_at' => null,
                'break_minutes' => ((int) $session->break_minutes) + $minutes,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'breakEvents']);
        });
    }

    /** @return array{0: HrAttendanceSession, 1: User} */
    protected function lockOpenSessionForUser(User $user, ?int $sessionId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Attendance break mutations must be locked inside a transaction.');
        }

        if ($sessionId === null) {
            $sessionId = HrAttendanceSession::query()
                ->where('user_id', $user->id)
                ->open()
                ->latest('clock_in_at')
                ->value('id');
        }
        if (! $sessionId) {
            throw new \LogicException('No open attendance session found.');
        }

        [$session, $lockedUser] = $this->lockCurrentAttendanceCommand($user, (int) $sessionId);
        abort_unless($this->canCorrectOwnSession($lockedUser), 403);
        abort_unless((int) $session->user_id === (int) $lockedUser->id, 404);
        if ($session->status !== 'open' || $session->clock_out_at) {
            throw new \LogicException('No open attendance session found.');
        }

        return [$session, $lockedUser];
    }

    protected function resolveShift(User $user, array $data, Carbon $clockInAt): ?Shift
    {
        if (! empty($data['shift_id'])) {
            $shift = $this->resolveSelfClockInShift($user, $data['shift_id']);
            abort_unless($shift !== null, 404);

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

        abort_unless((int) $session->user_id === (int) $user->id, 404);

        return $session;
    }

    protected function closeOpenBreakEvent(HrAttendanceSession $session, Carbon $clockOutAt, ?Carbon $startedAt): void
    {
        $events = HrAttendanceBreakEvent::query()
            ->where('session_id', $session->id)
            ->whereNull('ended_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if (($startedAt !== null && $events->count() !== 1)
            || ($startedAt === null && $events->isNotEmpty())) {
            throw new \LogicException('The active attendance break evidence is missing or conflicting.');
        }

        $events->first()?->update([
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
            abort(404);
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
     * Bind caller-supplied relationship hints to the already locked canonical
     * attendance aggregate. A Client ID is confirmation only and can never
     * select a different Client for payroll or task writes.
     */
    protected function assertClockOutPayloadScope(HrAttendanceSession $session, array $data): void
    {
        if (! array_key_exists('client_id', $data) || $data['client_id'] === null) {
            return;
        }

        $requestedClientId = $this->positiveId($data['client_id']);
        $canonicalClientId = $this->positiveId($session->shift?->client_id);
        abort_unless(
            $requestedClientId !== null
                && $canonicalClientId !== null
                && $requestedClientId === $canonicalClientId,
            404,
        );
    }

    /**
     * @param  array<string, mixed>|mixed  $handover
     */
    protected function saveHandoverFromClockOut(
        HrAttendanceSession $session,
        User $user,
        mixed $handover,
        array $additionalParticipantUserIds = [],
    ): void {
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

        // Optional task list from the clock-out wizard — discrete items for the
        // reviewed incoming handover, using the shared Shift Handover shape.
        $tasksPending = collect(is_array($handover['tasks_pending'] ?? null) ? $handover['tasks_pending'] : [])
            ->map(fn ($task) => trim((string) $task))
            ->filter(fn ($task) => $task !== '')
            ->map(fn ($task) => ['label' => $task])
            ->values()
            ->all();

        $payload = [
            'handover_notes' => $notes,
            'client_mood' => $handover['shift_rating'] ?? null,
            'follow_up_items' => $followUpNeeded
                ? [[
                    'label' => 'Follow-up flagged by outgoing worker',
                    'priority' => 'medium',
                ]]
                : null,
            'tasks_pending' => $tasksPending !== [] ? $tasksPending : null,
            // ShiftHandoverService resolves this to the currently locked draft
            // version. The clock-out form may safely refresh its own draft, but
            // never bypasses aggregate ownership or submitted immutability.
            'replace_owned_draft' => true,
            'submit' => false,
        ];

        if (
            $user->canDo('medications.controlled.view')
            && $user->canDo('medications.controlled.record')
        ) {
            $payload['medications_due'] = $medsCompleted
                ? null
                : [[
                    'label' => ShiftHandoverService::OUTSTANDING_MEDICATION_DUE_LABEL,
                    'severity' => 'high',
                ]];
        }

        app(ShiftHandoverService::class)->save(
            $shift,
            $user,
            $payload,
            $additionalParticipantUserIds,
        );
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
            ->effectiveClinicalEvidence()
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
