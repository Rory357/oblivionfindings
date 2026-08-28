<?php

namespace App\Domain\Shifts\Lifecycle;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CoverageReservationService;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\ShiftCancellationService;
use App\Services\ShiftHandoverService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStateGuardService;
use App\Services\ShiftTimelineService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiftLifecycleService
{
    /**
     * The lifecycle table is intentionally small and local to the service. The
     * service is also the persistence authorization boundary: route/controller
     * checks are only an early rejection and may be stale by the time a command
     * acquires the canonical Shift mutex.
     */
    private const TRANSITIONS = [
        'draft' => ['scheduled', 'in_progress', 'cancelled'],
        'scheduled' => ['in_progress', 'cancelled', 'draft'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => ['draft', 'scheduled'],
    ];

    /** @var array<int, string> */
    private const MUTATION_AUTHORIZATION_EVIDENCE = [
        'shifts.create',
        'shifts.update',
        'shifts.manageAny',
        'shifts.overrideEligibility',
        'shifts.viewAssigned',
        'timesheets.create',
        'timesheets.manageAny',
        'timesheets.approve',
        'reports.viewAny',
        'roster_templates.update',
        'rostering.edit',
        'rostering.autoSchedule',
    ];

    /** @var array<int, string> */
    private const ATTENDANCE_TRANSITION_PERMISSIONS = [
        'timesheets.create',
        'timesheets.manageAny',
        'timesheets.approve',
        'shifts.viewAssigned',
        'shifts.update',
        'shifts.manageAny',
    ];

    /** @var array<int, string> */
    private const BULK_ASSIGN_PERMISSIONS = [
        'roster_templates.update',
        'rostering.edit',
        'rostering.autoSchedule',
    ];

    /**
     * @var array{success: bool, reason: string|null, timesheet: Timesheet|null}
     */
    private array $lastDraftTimesheetResult = [
        'success' => false,
        'reason' => null,
        'timesheet' => null,
    ];

    public function __construct(
        protected ShiftTimelineService $timelineService,
        protected ShiftCancellationService $cancellationService,
        protected ShiftHandoverService $handoverService,
        protected CoverageReservationService $coverageReservationService,
        protected ShiftReplacementService $replacementService,
        protected ShiftStateGuardService $stateGuardService,
        protected DraftTimesheetService $draftTimesheets,
        protected AuthorizationEvidenceLockService $authorizationEvidence,
        protected MedicationGovernanceScopeService $medicationGovernance,
        protected AssignmentEligibilityGateway $assignmentEligibility,
    ) {}

    /**
     * @return array{success: bool, reason: string|null, timesheet: Timesheet|null}
     */
    public function lastDraftTimesheetResult(): array
    {
        return $this->lastDraftTimesheetResult;
    }

    public function create(
        array $attributes,
        User $actor,
        ShiftLifecycleSource|string|null $source = null,
    ): Shift {
        $source = $this->normalizeSource($source);

        return DB::transaction(function () use ($attributes, $actor, $source) {
            $this->lockApplicationShiftMutex();
            [$siteId] = $this->lockCanonicalCreateContext($attributes);
            [$actor, $lockedUsers] = $this->lockCurrentCreateAuthority(
                $actor,
                $siteId,
                $source === ShiftLifecycleSource::Bulk
                    ? ['roster_templates.update', 'rostering.edit']
                    : ['shifts.create'],
                ! empty($attributes['user_id']) ? [(int) $attributes['user_id']] : [],
                ! empty($attributes['user_id']) ? [(int) $attributes['user_id']] : [],
                $source === ShiftLifecycleSource::Bulk
                    ? ['reports.viewAny', 'shifts.manageAny']
                    : ['reports.viewAny'],
            );

            if (! empty($attributes['user_id'])) {
                $assignee = $lockedUsers->get((int) $attributes['user_id']);
                abort_unless($assignee instanceof User, 404);
                $attributes['user_id'] = (int) $assignee->id;
            }
            $attributes['site_id'] = $siteId;
            $attributes['created_by'] = $actor->id;
            $attributes['status'] ??= 'draft';

            $shift = Shift::query()->create($attributes);

            return $shift->fresh() ?? $shift;
        });
    }

    public function start(
        Shift $shift,
        User $actor,
        ?CarbonInterface $at = null,
        ShiftLifecycleSource|string|null $source = null,
    ): Shift {
        $source = $this->normalizeSource($source);
        $at ??= now();
        $transitioned = false;

        $started = DB::transaction(function () use ($shift, &$actor, $at, $source, &$transitioned) {
            $this->lockApplicationShiftMutex();
            $locked = $this->handoverService->lockCompletionShift($shift);
            [$actor] = $this->lockCurrentShiftAuthority(
                $locked,
                $actor,
                $source === ShiftLifecycleSource::ClockIn
                    ? self::ATTENDANCE_TRANSITION_PERMISSIONS
                    : ['shifts.update'],
                [(int) $locked->user_id],
                [(int) $locked->user_id],
                ['reports.viewAny'],
                true,
                ['shifts.manageAny'],
            );

            if ($locked->status === 'in_progress') {
                $dirty = false;
                if (! $locked->actual_starts_at) {
                    $locked->actual_starts_at = $at;
                    $dirty = true;
                }
                if (! $locked->started_by) {
                    $locked->started_by = $actor->id;
                    $dirty = true;
                }
                if ($dirty) {
                    $locked->save();
                }

                return $locked->fresh() ?? $locked;
            }

            $allowed = $source === ShiftLifecycleSource::ClockIn
                ? ['draft', 'scheduled']
                : ['scheduled'];

            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only scheduled shifts can be started.',
                ]);
            }

            if (! $locked->user_id) {
                throw ValidationException::withMessages([
                    'status' => 'Only assigned shifts can be started. Assign a staff member before starting the shift.',
                ]);
            }

            $this->assertTransitionAllowed((string) $locked->status, 'in_progress');

            $locked->update([
                'status' => 'in_progress',
                'actual_starts_at' => $locked->actual_starts_at ?? $at,
                'started_by' => $locked->started_by ?? $actor->id,
            ]);

            $transitioned = true;

            return $locked->fresh() ?? $locked;
        });

        if ($transitioned) {
            $this->timelineService->recordStarted(
                $started,
                $actor,
                $started->actual_starts_at ?? $at,
            );
        }

        return $started;
    }

    public function complete(Shift $shift, User $actor, CompleteShiftData $data): Shift
    {
        $this->lastDraftTimesheetResult = [
            'success' => false,
            'reason' => 'Not attempted',
            'timesheet' => null,
        ];
        $handoverRequirement = null;
        $handoverWaiverReason = trim((string) $data->handoverWaiverReason);
        $transitioned = false;
        $timesheetResult = $this->lastDraftTimesheetResult;
        $actualStartAt = null;
        $actualEndAt = null;
        $incompleteTasks = collect();
        $finalBody = trim((string) $data->finalNoteBody);

        $completed = DB::transaction(function () use ($shift, &$actor, $data, &$incompleteTasks, $finalBody, &$handoverRequirement, &$handoverWaiverReason, &$actualStartAt, &$actualEndAt, &$timesheetResult, &$transitioned) {
            $this->lockApplicationShiftMutex();
            $now = now();
            $locked = $this->handoverService->lockCompletionShift($shift);
            $isAttendanceCompletion = $data->source === ShiftLifecycleSource::ClockOut;
            [$actor] = $this->lockCurrentShiftAuthority(
                $locked,
                $actor,
                $isAttendanceCompletion
                    ? self::ATTENDANCE_TRANSITION_PERMISSIONS
                    : ['shifts.update'],
                [(int) $locked->user_id],
                [(int) $locked->user_id],
                ['reports.viewAny'],
                true,
                $isAttendanceCompletion
                    ? ['shifts.manageAny', 'timesheets.manageAny']
                    : ['shifts.manageAny'],
                $isAttendanceCompletion,
            );

            if ($locked->status === 'completed') {
                if (! $locked->completed_by) {
                    $locked->forceFill(['completed_by' => $actor->id])->save();
                }

                if ($this->shouldSyncDraftTimesheet($data)) {
                    $timesheetResult = $this->draftTimesheets->fromShift($locked->fresh() ?? $locked, $actor->id);
                }

                return $locked->fresh() ?? $locked;
            }

            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'Only in-progress shifts can be completed. Start the shift first.',
                ]);
            }

            // Recompute every mutable completion blocker only after acquiring
            // the canonical Client/Shift mutex. Lock the evidence rows in a
            // deterministic order so the decision remains true until commit.
            $attendanceSessions = HrAttendanceSession::query()
                ->where('shift_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'user_id', 'shift_id', 'clock_in_at', 'clock_out_at', 'break_minutes', 'status']);
            $openAttendanceSession = $attendanceSessions->first(
                fn (HrAttendanceSession $session) => $session->status === 'open' || ! $session->clock_out_at,
            );
            if ($openAttendanceSession) {
                throw ValidationException::withMessages([
                    'status' => 'This shift has an open attendance session. Clock out before completing the shift.',
                ]);
            }
            $assignedAttendanceSessions = $attendanceSessions
                ->when($locked->user_id, fn ($sessions) => $sessions->where('user_id', $locked->user_id))
                ->values();

            $actualStartAt = $data->actualStartsAt
                ?: $locked->actual_starts_at
                ?: $assignedAttendanceSessions
                    ->whereNotNull('clock_in_at')
                    ->sortBy('clock_in_at')
                    ->first()?->clock_in_at;
            if (! $actualStartAt) {
                throw ValidationException::withMessages([
                    'status' => 'This shift has no actual start evidence. Start the shift or record attendance before completing it.',
                ]);
            }

            $actualEndAt = $data->actualEndsAt
                ?: $assignedAttendanceSessions
                    ->whereNotNull('clock_out_at')
                    ->sortByDesc('clock_out_at')
                    ->first()?->clock_out_at
                ?: $now;

            $tasks = ShiftTask::query()
                ->where('shift_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $incompleteTasks = $tasks->where('is_completed', false)->values();

            if ($data->createSummaryNote && $finalBody === '') {
                $existingNotes = ClientNote::query()
                    ->where('shift_id', $locked->id)
                    ->whereIn('type', ['progress_note', 'shift_note'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                if ($existingNotes->isEmpty()) {
                    throw ValidationException::withMessages([
                        'final_note_body' => 'Add at least one progress note during the shift or provide a shift summary note to complete the shift.',
                    ]);
                }
            }

            if ($incompleteTasks->isNotEmpty() && ! $data->allowIncompleteTasks) {
                throw ValidationException::withMessages([
                    'allow_incomplete_tasks' => 'This shift still has incomplete tasks. Complete all tasks or allow completion with a reason.',
                ]);
            }

            if ($incompleteTasks->isNotEmpty()
                && $data->allowIncompleteTasks
                && trim((string) $data->incompleteTasksReason) === '') {
                throw ValidationException::withMessages([
                    'incomplete_tasks_reason' => 'Please provide a reason for completing with incomplete tasks.',
                ]);
            }

            $handoverRequirement = $this->handoverService->completionRequirement(
                $locked,
                $actualEndAt,
                true,
            );

            if (($handoverRequirement['requires_handover'] ?? false)
                && ! $handoverRequirement['matched_handover']) {
                if ($data->deferCompletionUntilHandoverSubmitted) {
                    return $locked->fresh() ?? $locked;
                }

                if ($handoverWaiverReason === '' && $data->autoWaiveHandover) {
                    $handoverWaiverReason = 'clock_out_auto_complete';
                }

                if ($handoverWaiverReason === '') {
                    throw ValidationException::withMessages([
                        'handover_waiver_reason' => $handoverRequirement['reason'],
                    ]);
                }
            }

            $this->assertTransitionAllowed((string) $locked->status, 'completed');

            $locked->update([
                'status' => 'completed',
                'actual_starts_at' => $actualStartAt,
                'actual_ends_at' => $actualEndAt,
                'started_by' => $locked->started_by ?? $actor->id,
                'completed_by' => $data->source === ShiftLifecycleSource::ClockOut
                    ? ($locked->completed_by ?? $actor->id)
                    : $actor->id,
                'handover_waiver_reason' => null,
                'handover_waived_at' => null,
                'handover_waived_by' => null,
            ]);

            $transitioned = true;
            $locked = $locked->fresh(['client']) ?? $locked;

            if (($handoverRequirement['requires_handover'] ?? false)
                && ! $handoverRequirement['matched_handover']
                && $handoverWaiverReason !== '') {
                $this->handoverService->recordCompletionWaiver(
                    $locked,
                    $actor,
                    $handoverWaiverReason,
                    $handoverRequirement,
                );
            }

            if ($data->createSummaryNote) {
                $this->createCompletionSummaryNote(
                    $locked,
                    $actor,
                    $now,
                    $data,
                    $finalBody,
                    $incompleteTasks->count(),
                    $handoverWaiverReason,
                );
            }

            if ($this->shouldSyncDraftTimesheet($data)) {
                try {
                    $timesheetResult = $this->draftTimesheets->fromShift($locked->fresh() ?? $locked, $actor->id);
                } catch (\Throwable $e) {
                    $timesheetResult = [
                        'success' => false,
                        'reason' => $e->getMessage(),
                        'timesheet' => null,
                    ];

                    Log::error('Timesheet creation failed on shift completion', [
                        'shift_id' => $locked->id,
                        'user_id' => $locked->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $locked->fresh() ?? $locked;
        });

        $this->lastDraftTimesheetResult = $timesheetResult;

        if ($transitioned) {
            $this->timelineService->recordCompleted(
                $completed,
                $actor,
                $completed->actual_ends_at ?? now(),
                array_merge([
                    'completed_with_incomplete_tasks' => $incompleteTasks->count() > 0 ? true : null,
                    'incomplete_tasks_reason' => $data->allowIncompleteTasks ? (string) $data->incompleteTasksReason : null,
                    'incomplete_task_count' => $incompleteTasks->count() ?: null,
                    'handover_required' => ($handoverRequirement['requires_handover'] ?? false) ? true : null,
                    'handover_id' => $handoverRequirement['matched_handover']?->id,
                    'handover_waiver_reason' => $handoverWaiverReason !== '' ? $handoverWaiverReason : null,
                    'timesheet_created' => $timesheetResult['success'],
                    'source' => $data->source->value,
                ], $data->timelineMeta),
            );
        }

        return $completed;
    }

    public function cancel(Shift $shift, User $actor, ?string $reason = null): Shift
    {
        return DB::transaction(function () use ($shift, $actor) {
            $this->lockApplicationShiftMutex();
            $locked = $this->lockCanonicalLifecycleShift($shift);
            [$actor] = $this->lockCurrentShiftAuthority(
                $locked,
                $actor,
                ['shifts.manageAny'],
            );
            $result = $this->cancellationService->cancel($locked, $actor);

            return $result['shift'];
        });
    }

    public function reopen(Shift $shift, User $actor, ?string $reason = null): Shift
    {
        if (! in_array($shift->status, ['cancelled', 'completed'], true)) {
            return $shift->fresh() ?? $shift;
        }

        $wasCompleted = $shift->status === 'completed';

        return DB::transaction(function () use ($shift, &$actor, $reason, &$wasCompleted) {
            $this->lockApplicationShiftMutex();
            $locked = $this->lockCanonicalLifecycleShift($shift);
            [$actor] = $this->lockCurrentShiftAuthority(
                $locked,
                $actor,
                ['shifts.manageAny'],
            );

            if (! in_array($locked->status, ['cancelled', 'completed'], true)) {
                return $locked->fresh() ?? $locked;
            }

            $wasCompleted = $locked->status === 'completed';

            $locked->update([
                'status' => $this->stateGuardService->normalizePlanningStatus(
                    $locked->user_id ? 'scheduled' : 'draft',
                    ! empty($locked->user_id),
                ),
                'actual_starts_at' => null,
                'actual_ends_at' => null,
                'started_by' => null,
                'completed_by' => null,
            ]);

            TimelineEvent::query()
                ->where('type', $wasCompleted
                    ? ShiftTimelineService::COMPLETED_EVENT_TYPE
                    : ShiftTimelineService::CANCELLED_EVENT_TYPE)
                ->where('source_type', Shift::class)
                ->where('source_id', $locked->id)
                ->delete();

            $fresh = $locked->fresh() ?? $locked;
            $this->timelineService->syncSnapshot($fresh);

            // Audit entry capturing who reopened it and why. We reuse
            // the snapshot event with a synthetic 'reopened' meta so the
            // event remains visible in the shift timeline without
            // introducing a new event type.
            TimelineEvent::create([
                'type' => 'shift_reopened',
                'source_type' => Shift::class,
                'source_id' => $fresh->id,
                'occurred_at' => now(),
                'actor_user_id' => $actor->id,
                'client_id' => $fresh->client_id,
                'shift_id' => $fresh->id,
                'site_id' => $fresh->site_id ?: $fresh->client?->site_id,
                'subject' => 'Shift reopened',
                'body' => $reason
                    ? 'Reopened from '.($wasCompleted ? 'completed' : 'cancelled').'. Reason: '.$reason
                    : 'Reopened from '.($wasCompleted ? 'completed' : 'cancelled').'.',
                'meta' => array_filter([
                    'event' => 'reopened',
                    'previous_status' => $wasCompleted ? 'completed' : 'cancelled',
                    'reason' => $reason,
                ], fn ($value) => $value !== null && $value !== ''),
                'visibility' => 'internal',
                'created_by' => $actor->id,
            ]);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>|null  $overrideData
     */
    public function assign(
        Shift $shift,
        User $actor,
        User $assignee,
        ?array $overrideData = null,
        mixed $reservation = null,
        ?AssignmentEligibilityDecision $eligibilityDecision = null,
        ShiftLifecycleSource|string|null $source = null,
        ?string $reservationReason = null,
    ): Shift {
        $source = $this->normalizeSource($source);
        $originalUserId = null;

        $assigned = DB::transaction(function () use (
            $shift,
            &$actor,
            &$assignee,
            $overrideData,
            &$reservation,
            &$eligibilityDecision,
            $source,
            $reservationReason,
            &$originalUserId,
        ) {
            $this->lockApplicationShiftMutex();
            $locked = $this->lockCanonicalLifecycleShift($shift);
            [$actor, $lockedUsers] = $this->lockCurrentShiftAuthority(
                $locked,
                $actor,
                $source === ShiftLifecycleSource::Bulk
                    ? self::BULK_ASSIGN_PERMISSIONS
                    : ['shifts.manageAny'],
                [(int) $assignee->id],
                [(int) $assignee->id],
                $source === ShiftLifecycleSource::Bulk
                    ? ['reports.viewAny', 'shifts.manageAny']
                    : ['reports.viewAny'],
            );
            $assignee = $lockedUsers->get((int) $assignee->id);
            abort_unless($assignee instanceof User, 404);
            $originalUserId = $locked->user_id ? (int) $locked->user_id : null;

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'user_id' => 'This shift was changed by another scheduler and can no longer be assigned.',
                ]);
            }

            // Any controller/suggestion decision was a pre-wait hint. Re-run
            // the complete gateway only after the canonical Shift and the
            // assignee's current User/RBAC/Profile/Site evidence are locked.
            $eligibilityDecision = $this->assignmentEligibility->decide($locked, $assignee);
            $eligibilityDecision->assertMayAssign(
                'user_id',
                'This staff member cannot be assigned to the shift.',
            );
            $currentOverrideData = $this->currentEligibilityOverrideData(
                $eligibilityDecision,
                $actor,
                $assignee,
                $overrideData,
            );

            if ($reservation === null && $reservationReason !== null) {
                $reservation = $this->coverageReservationService->reserveForAssignment(
                    $locked,
                    $actor,
                    $reservationReason,
                );
            }

            $locked->update([
                'user_id' => $assignee->id,
                'status' => $locked->status === 'draft' ? 'scheduled' : $locked->status,
            ]);

            if ($currentOverrideData) {
                ShiftEligibilityOverride::create([
                    'shift_id' => $locked->id,
                    ...$currentOverrideData,
                ]);
            }

            if ($reservation) {
                $this->coverageReservationService->fulfill($reservation, $locked);
            }

            return $locked->fresh() ?? $locked;
        });

        if ((int) $originalUserId !== (int) $assignee->id) {
            $this->timelineService->recordAssigned(
                $assigned,
                $assignee,
                $actor,
                $originalUserId ? (int) $originalUserId : null,
            );
        }

        if ($originalUserId && (int) $originalUserId !== (int) $assignee->id) {
            $this->replacementService->resolveFromManualAssignment(
                $assigned,
                (int) $assignee->id,
                $actor,
                $eligibilityDecision,
            );
        }

        return $assigned->fresh() ?? $assigned;
    }

    /**
     * Build override provenance only from the current locked decision and
     * actor. Caller-supplied user/rule/actor fields are intentionally ignored.
     *
     * @param  array<string, mixed>|null  $overrideRequest
     * @return array<string, mixed>|null
     */
    protected function currentEligibilityOverrideData(
        AssignmentEligibilityDecision $decision,
        User $actor,
        User $assignee,
        ?array $overrideRequest,
    ): ?array {
        if (! $decision->isWarning()) {
            return null;
        }

        if (! (bool) ($overrideRequest['override_acknowledged'] ?? false)) {
            throw ValidationException::withMessages([
                'override_acknowledged' => 'Review and acknowledge the current eligibility warnings before assigning this worker.',
            ]);
        }

        $overrideableWarnings = $decision->result?->overrideable_warnings ?? [];
        if ($overrideableWarnings === []) {
            return null;
        }

        abort_unless(
            $actor->canDo('shifts.overrideEligibility'),
            403,
            'You do not have permission to override eligibility warnings.',
        );
        $reason = trim((string) ($overrideRequest['override_reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'override_reason' => 'A reason is required when overriding eligibility warnings.',
            ]);
        }

        return [
            'user_id' => (int) $assignee->id,
            'overridden_by' => (int) $actor->id,
            'override_reason' => $reason,
            'rules_overridden' => collect($overrideableWarnings)->pluck('rule')->values()->all(),
            'acknowledged_warnings' => $overrideableWarnings,
        ];
    }

    public function unassign(Shift $shift, User $actor, ?string $reason = null): Shift
    {
        $previousStaff = null;

        $unassigned = DB::transaction(function () use ($shift, &$actor, &$previousStaff) {
            $this->lockApplicationShiftMutex();
            $locked = $this->lockCanonicalLifecycleShift($shift);
            [$actor] = $this->lockCurrentShiftAuthority(
                $locked,
                $actor,
                ['shifts.manageAny'],
            );

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'shift' => 'This shift is locked and can no longer be unassigned.',
                ]);
            }

            if ($locked->status === 'in_progress') {
                throw ValidationException::withMessages([
                    'shift' => 'In-progress shifts cannot be unassigned. Use the replacement workflow instead.',
                ]);
            }

            $locked->loadMissing(['staff:id,name']);
            $previousStaff = $locked->staff;

            $this->coverageReservationService->releaseForShift($locked);

            $locked->update([
                'user_id' => null,
                'status' => 'draft',
            ]);

            return $locked->fresh() ?? $locked;
        });

        $this->timelineService->recordUnassigned(
            $unassigned,
            $previousStaff,
            $actor,
            $reason,
        );

        return $unassigned->fresh() ?? $unassigned;
    }

    /**
     * Serialize lifecycle commands with Attendance/TimeTracking commands that
     * already take this mutex before their User/Profile locks. This makes the
     * shared order application mutex -> ServiceContext -> Client/Shift ->
     * User/Profile and avoids a same-worker User -> Shift / Shift -> User
     * cycle.
     */
    private function lockApplicationShiftMutex(): void
    {
        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();
        if (! $mutex) {
            throw new \LogicException('The application payroll mutex is missing; migration repair is required.');
        }
    }

    /**
     * Resolve the one Site represented by a new Shift before it exists. The
     * canonical Client is mandatory and authoritative; an optional service
     * context may add operational context but cannot replace ownership.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: int, 1: Client, 2: ServiceContext|null}
     */
    private function lockCanonicalCreateContext(array $attributes): array
    {
        $siteId = is_numeric($attributes['site_id'] ?? null)
            ? (int) $attributes['site_id']
            : 0;
        $clientId = is_numeric($attributes['client_id'] ?? null)
            ? (int) $attributes['client_id']
            : 0;
        $serviceContextId = is_numeric($attributes['service_context_id'] ?? null)
            ? (int) $attributes['service_context_id']
            : 0;
        abort_unless($siteId > 0 && $clientId > 0, 404);

        // ServiceContext is the shared first aggregate row for Shift create,
        // medication-round mutation, and round materialization. Lock it before
        // a submitted Client so Client+Context roster occurrences cannot cycle
        // with a round worker holding the same Context and waiting on Client.
        $serviceContext = null;
        if ($serviceContextId > 0) {
            $serviceContext = ServiceContext::query()
                ->whereKey($serviceContextId)
                ->where('is_active', true)
                ->where(function ($query) use ($siteId): void {
                    $query->whereNull('site_id')->orWhere('site_id', $siteId);
                })
                ->lockForUpdate()
                ->first();
            abort_unless($serviceContext instanceof ServiceContext, 404);
        }

        $client = Client::query()
            ->whereKey($clientId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first();
        abort_unless($client instanceof Client, 404);

        return [$siteId, $client, $serviceContext];
    }

    /**
     * Lock current actor/assignee evidence for a Shift create before the Shift
     * row exists and can become the aggregate mutex.
     *
     * @param  array<int, string>  $requiredActionPermissions
     * @param  array<int, int>  $participantUserIds
     * @param  array<int, int>  $siteBoundUserIds
     * @param  array<int, string>  $siteBypassPermissions
     * @return array{0: User, 1: Collection<int, User>}
     */
    private function lockCurrentCreateAuthority(
        User $actor,
        int $siteId,
        array $requiredActionPermissions,
        array $participantUserIds = [],
        array $siteBoundUserIds = [],
        array $siteBypassPermissions = ['reports.viewAny'],
    ): array {
        $lockedUsers = $this->authorizationEvidence->lockForUsers(
            [(int) $actor->id, ...$participantUserIds],
            self::MUTATION_AUTHORIZATION_EVIDENCE,
        );
        $lockedActor = $lockedUsers->get((int) $actor->id);
        abort_unless($lockedActor instanceof User, 404);

        $siteBoundUserIds = collect($siteBoundUserIds)
            ->map(fn ($userId): int => (int) $userId)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->unique()
            ->sort()
            ->values();
        $profileUserIds = collect([(int) $lockedActor->id, ...$siteBoundUserIds->all()])
            ->unique()
            ->sort()
            ->values();
        $profiles = $this->medicationGovernance->lockCurrentStaffProfiles(
            $lockedUsers,
            $profileUserIds->all(),
        );
        $profileUserIds->each(function (int $userId) use ($lockedUsers, $profiles): void {
            $lockedUsers->get($userId)?->setRelation('hrEmployeeProfile', $profiles->get($userId));
        });
        $actorProfile = $profiles->get((int) $lockedActor->id);
        abort_unless($actorProfile instanceof HrEmployeeProfile, 404);
        abort_unless($siteBoundUserIds->every(function (int $userId) use ($profiles, $siteId): bool {
            $profile = $profiles->get($userId);

            return $profile instanceof HrEmployeeProfile
                && $this->profileHasSite($profile, $siteId);
        }), 404);

        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($site instanceof Site, 403, 'You are not authorized to create shifts for this site.');

        $canBypassSite = collect($siteBypassPermissions)
            ->contains(fn (string $permission): bool => $lockedActor->canDo($permission));
        abort_unless(
            $canBypassSite || $this->profileHasSite($actorProfile, $siteId),
            403,
            'You are not authorized to create shifts for this site.',
        );
        abort_unless(
            collect($requiredActionPermissions)
                ->contains(fn (string $permission): bool => $lockedActor->canDo($permission)),
            403,
        );

        return [$lockedActor, $lockedUsers];
    }

    /**
     * Lock the immutable Client/Site provenance before a lifecycle mutation.
     * The initial model is only an identity hint; all authorization decisions
     * below use this constrained, current Shift row.
     */
    private function lockCanonicalLifecycleShift(Shift $shift): Shift
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Shift lifecycle mutations must be locked inside a transaction.');
        }

        $snapshot = Shift::query()
            ->whereKey($shift->id)
            ->first(['id', 'client_id', 'site_id']);
        abort_unless(
            $snapshot !== null
                && is_numeric($snapshot->client_id)
                && is_numeric($snapshot->site_id)
                && (int) $snapshot->client_id > 0
                && (int) $snapshot->site_id > 0,
            404,
        );

        $client = Client::query()
            ->whereKey((int) $snapshot->client_id)
            ->where('site_id', (int) $snapshot->site_id)
            ->lockForUpdate()
            ->first();
        abort_unless($client instanceof Client, 404);

        $locked = Shift::query()
            ->whereKey($snapshot->id)
            ->where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->lockForUpdate()
            ->first();
        abort_unless($locked instanceof Shift, 404);
        $locked->setRelation('client', $client);
        $locked->loadMissing([
            'site:id,name,type',
            'serviceContext:id,name,type',
            'staff:id,name',
        ]);

        return $locked;
    }

    /**
     * Join a canonical Shift lock to current User/RBAC, employment, and Site
     * evidence. Every participant is locked in one sorted set so assignment
     * commands cannot invert actor/assignee User mutexes.
     *
     * @param  array<int, string>  $requiredActionPermissions
     * @param  array<int, int|null>  $participantUserIds
     * @param  array<int, int|null>  $siteBoundUserIds
     * @param  array<int, string>  $siteBypassPermissions
     * @param  array<int, string>  $assignmentBypassPermissions
     * @return array{0: User, 1: Collection<int, User>}
     */
    private function lockCurrentShiftAuthority(
        Shift $lockedShift,
        User $actor,
        array $requiredActionPermissions,
        array $participantUserIds = [],
        array $siteBoundUserIds = [],
        array $siteBypassPermissions = ['reports.viewAny'],
        bool $requireAssignedAuthority = false,
        array $assignmentBypassPermissions = [],
        bool $allowManagedAssignee = false,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Shift authorization evidence must be locked inside a transaction.');
        }

        $siteId = is_numeric($lockedShift->site_id) ? (int) $lockedShift->site_id : 0;
        abort_unless(
            $siteId > 0
                && is_numeric($lockedShift->client_id)
                && (int) $lockedShift->client_id > 0
                && $lockedShift->client instanceof Client
                && (int) $lockedShift->client->id === (int) $lockedShift->client_id
                && (int) $lockedShift->client->site_id === $siteId,
            404,
        );

        $lockedUsers = $this->authorizationEvidence->lockForUsers(
            [(int) $actor->id, ...$participantUserIds],
            self::MUTATION_AUTHORIZATION_EVIDENCE,
        );
        $lockedActor = $lockedUsers->get((int) $actor->id);
        abort_unless($lockedActor instanceof User, 404);

        $siteBoundUserIds = collect($siteBoundUserIds)
            ->map(fn ($userId): int => (int) $userId)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->unique()
            ->sort()
            ->values();
        $profileUserIds = collect([(int) $lockedActor->id, ...$siteBoundUserIds->all()])
            ->unique()
            ->sort()
            ->values();
        $profiles = $this->medicationGovernance->lockCurrentStaffProfiles(
            $lockedUsers,
            $profileUserIds->all(),
        );
        $profileUserIds->each(function (int $userId) use ($lockedUsers, $profiles): void {
            $lockedUsers->get($userId)?->setRelation('hrEmployeeProfile', $profiles->get($userId));
        });
        $actorProfile = $profiles->get((int) $lockedActor->id);
        abort_unless($actorProfile instanceof HrEmployeeProfile, 404);

        abort_unless($siteBoundUserIds->every(function (int $userId) use ($profiles, $siteId): bool {
            $profile = $profiles->get($userId);

            return $profile instanceof HrEmployeeProfile
                && $this->profileHasSite($profile, $siteId);
        }), 404);

        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($site instanceof Site, 403, 'You are not authorized to access shifts for this site.');

        $canBypassSite = collect($siteBypassPermissions)
            ->contains(fn (string $permission): bool => $lockedActor->canDo($permission));
        abort_unless(
            $canBypassSite || $this->profileHasSite($actorProfile, $siteId),
            403,
            'You are not authorized to access shifts for this site.',
        );
        abort_unless(
            collect($requiredActionPermissions)
                ->contains(fn (string $permission): bool => $lockedActor->canDo($permission)),
            403,
        );

        if ($requireAssignedAuthority) {
            $canBypassAssignment = collect($assignmentBypassPermissions)
                ->contains(fn (string $permission): bool => $lockedActor->canDo($permission));
            $assigneeProfile = $profiles->get((int) $lockedShift->user_id);
            $canManageAssignee = $allowManagedAssignee
                && $lockedActor->canDo('timesheets.approve')
                && $assigneeProfile instanceof HrEmployeeProfile
                && (int) $assigneeProfile->manager_user_id === (int) $lockedActor->id;
            abort_unless(
                (int) $lockedShift->user_id === (int) $lockedActor->id
                    || $canBypassAssignment
                    || $canManageAssignee,
                403,
            );
        }

        return [$lockedActor, $lockedUsers];
    }

    private function profileHasSite(HrEmployeeProfile $profile, int $siteId): bool
    {
        return (int) $profile->primary_site_id === $siteId
            || collect($profile->secondary_site_ids ?? [])->contains(
                fn ($candidate): bool => (int) $candidate === $siteId,
            );
    }

    private function normalizeSource(ShiftLifecycleSource|string|null $source): ShiftLifecycleSource
    {
        if ($source instanceof ShiftLifecycleSource) {
            return $source;
        }

        if (is_string($source) && $source !== '') {
            return ShiftLifecycleSource::tryFrom($source) ?? ShiftLifecycleSource::Manual;
        }

        return ShiftLifecycleSource::Manual;
    }

    private function shouldSyncDraftTimesheet(CompleteShiftData $data): bool
    {
        return $data->syncDraftTimesheet
            && $data->source !== ShiftLifecycleSource::ClockOut;
    }

    private function assertTransitionAllowed(string $from, string $to): void
    {
        if (in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return;
        }

        throw new ShiftLifecycleException("Invalid shift transition [{$from}] -> [{$to}].");
    }

    private function createCompletionSummaryNote(
        Shift $shift,
        User $actor,
        CarbonInterface $occurredAt,
        CompleteShiftData $data,
        string $finalBody,
        int $incompleteTaskCount,
        string $handoverWaiverReason,
    ): void {
        $subject = trim((string) ($data->finalNoteSubject ?? 'Shift summary'));
        $body = $finalBody !== ''
            ? $finalBody
            : 'Shift completed - see shift notes for details.';

        $note = ClientNote::create([
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'user_id' => $actor->id,
            'type' => 'shift_note',
            'subject' => $subject,
            'body' => $body,
            'occurred_at' => $occurredAt,
            'visibility' => 'internal',
            'is_pinned' => false,
        ]);

        TimelineEvent::query()->updateOrCreate(
            [
                'type' => 'shift_note',
                'source_type' => ClientNote::class,
                'source_id' => $note->id,
            ],
            [
                'occurred_at' => $occurredAt,
                'actor_user_id' => $actor->id,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->client?->site_id,
                'subject' => $subject,
                'body' => $body,
                'meta' => array_filter([
                    'note_id' => $note->id,
                    'completed_with_incomplete_tasks' => $incompleteTaskCount > 0 ? true : null,
                    'incomplete_tasks_reason' => $data->allowIncompleteTasks ? (string) $data->incompleteTasksReason : null,
                    'incomplete_task_count' => $incompleteTaskCount ?: null,
                    'handover_waiver_reason' => $handoverWaiverReason !== '' ? $handoverWaiverReason : null,
                ]),
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $actor->id,
            ]
        );
    }
}
