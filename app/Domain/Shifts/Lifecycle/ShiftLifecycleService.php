<?php

namespace App\Domain\Shifts\Lifecycle;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\ShiftCancellationService;
use App\Services\ShiftHandoverService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStateGuardService;
use App\Services\ShiftTimelineService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiftLifecycleService
{
    /**
     * The lifecycle table is intentionally small and local to the service. The
     * service is a workflow boundary, not a permission boundary: callers must
     * authorize before invoking it.
     */
    private const TRANSITIONS = [
        'draft' => ['scheduled', 'in_progress', 'cancelled'],
        'scheduled' => ['in_progress', 'cancelled', 'draft'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => ['draft', 'scheduled'],
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
    ) {}

    /**
     * @return array{success: bool, reason: string|null, timesheet: Timesheet|null}
     */
    public function lastDraftTimesheetResult(): array
    {
        return $this->lastDraftTimesheetResult;
    }

    public function create(array $attributes, User $actor): Shift
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $attributes['created_by'] ??= $actor->id;
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

        $started = DB::transaction(function () use ($shift, $actor, $at, $source, &$transitioned) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

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

        $shift->loadMissing([
            'tasks',
            'client',
            'staff:id,name',
            'site:id,name',
            'serviceContext:id,name,type',
            'attendanceSessions:id,user_id,shift_id,clock_in_at,clock_out_at,break_minutes,status',
        ]);

        $attendanceSessions = $shift->attendanceSessions
            ->when($shift->user_id, fn ($collection) => $collection->where('user_id', $shift->user_id))
            ->values();
        $openAttendanceSession = $attendanceSessions->first(
            fn (HrAttendanceSession $session) => $session->status === 'open' || ! $session->clock_out_at
        );
        $actualStartAt = $data->actualStartsAt
            ?: $shift->actual_starts_at
            ?: $attendanceSessions
                ->whereNotNull('clock_in_at')
                ->sortBy('clock_in_at')
                ->first()?->clock_in_at;
        $actualEndAt = $data->actualEndsAt
            ?: $attendanceSessions
                ->whereNotNull('clock_out_at')
                ->sortByDesc('clock_out_at')
                ->first()?->clock_out_at
            ?: now();
        $incompleteTasks = $shift->tasks->where('is_completed', false)->values();
        $handoverRequirement = $this->handoverService->completionRequirement($shift);
        $handoverWaiverReason = trim((string) $data->handoverWaiverReason);
        $transitioned = false;
        $timesheetResult = $this->lastDraftTimesheetResult;

        if ($shift->status === 'completed') {
            $completed = DB::transaction(function () use ($shift, $actor, $data, &$timesheetResult) {
                $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

                if (! $locked->completed_by) {
                    $locked->forceFill(['completed_by' => $actor->id])->save();
                }

                if ($this->shouldSyncDraftTimesheet($data)) {
                    $timesheetResult = $this->draftTimesheets->fromShift($locked->fresh() ?? $locked, $actor->id);
                }

                return $locked->fresh() ?? $locked;
            });

            $this->lastDraftTimesheetResult = $timesheetResult;

            return $completed;
        }

        if ($shift->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'status' => 'Only in-progress shifts can be completed. Start the shift first.',
            ]);
        }

        if ($openAttendanceSession) {
            throw ValidationException::withMessages([
                'status' => 'This shift has an open attendance session. Clock out before completing the shift.',
            ]);
        }

        if (! $actualStartAt) {
            throw ValidationException::withMessages([
                'status' => 'This shift has no actual start evidence. Start the shift or record attendance before completing it.',
            ]);
        }

        $finalBody = trim((string) $data->finalNoteBody);
        if ($data->createSummaryNote && $finalBody === '') {
            $existingNoteCount = ClientNote::query()
                ->where('shift_id', $shift->id)
                ->whereIn('type', ['progress_note', 'shift_note'])
                ->count();

            if ($existingNoteCount === 0) {
                throw ValidationException::withMessages([
                    'final_note_body' => 'Add at least one progress note during the shift or provide a shift summary note to complete the shift.',
                ]);
            }
        }

        if ($incompleteTasks->count() > 0 && ! $data->allowIncompleteTasks) {
            throw ValidationException::withMessages([
                'allow_incomplete_tasks' => 'This shift still has incomplete tasks. Complete all tasks or allow completion with a reason.',
            ]);
        }

        if ($incompleteTasks->count() > 0
            && $data->allowIncompleteTasks
            && trim((string) $data->incompleteTasksReason) === '') {
            throw ValidationException::withMessages([
                'incomplete_tasks_reason' => 'Please provide a reason for completing with incomplete tasks.',
            ]);
        }

        if (($handoverRequirement['requires_handover'] ?? false)
            && ! $handoverRequirement['matched_handover']
            && $handoverWaiverReason === ''
            && $data->autoWaiveHandover) {
            $handoverWaiverReason = 'clock_out_auto_complete';
        }

        if (($handoverRequirement['requires_handover'] ?? false)
            && ! $handoverRequirement['matched_handover']
            && $handoverWaiverReason === '') {
            throw ValidationException::withMessages([
                'handover_waiver_reason' => $handoverRequirement['reason'],
            ]);
        }

        $completed = DB::transaction(function () use ($shift, $actor, $data, $incompleteTasks, $finalBody, $handoverRequirement, $handoverWaiverReason, $actualStartAt, $actualEndAt, &$timesheetResult, &$transitioned) {
            $now = now();
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

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
        $result = $this->cancellationService->cancel($shift, $actor);

        return $result['shift'];
    }

    public function reopen(Shift $shift, User $actor): Shift
    {
        if ($shift->status !== 'cancelled') {
            return $shift->fresh() ?? $shift;
        }

        return DB::transaction(function () use ($shift) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->status !== 'cancelled') {
                return $locked->fresh() ?? $locked;
            }

            $locked->update([
                'status' => $this->stateGuardService->normalizePlanningStatus(
                    $locked->user_id ? 'scheduled' : 'draft',
                    ! empty($locked->user_id),
                ),
                'actual_starts_at' => null,
                'actual_ends_at' => null,
            ]);

            TimelineEvent::query()
                ->where('type', ShiftTimelineService::CANCELLED_EVENT_TYPE)
                ->where('source_type', Shift::class)
                ->where('source_id', $locked->id)
                ->delete();

            $fresh = $locked->fresh() ?? $locked;
            $this->timelineService->syncSnapshot($fresh);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>|null  $overrideData
     */
    public function assign(Shift $shift, User $actor, User $assignee, ?array $overrideData = null, mixed $reservation = null): Shift
    {
        $originalUserId = $shift->user_id;

        $assigned = DB::transaction(function () use ($shift, $assignee, $overrideData, $reservation) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'user_id' => 'This shift was changed by another scheduler and can no longer be assigned.',
                ]);
            }

            $locked->update([
                'user_id' => $assignee->id,
                'status' => $locked->status === 'draft' ? 'scheduled' : $locked->status,
            ]);

            if ($overrideData) {
                ShiftEligibilityOverride::create([
                    'shift_id' => $locked->id,
                    ...$overrideData,
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
            $this->replacementService->resolveFromManualAssignment($assigned, (int) $assignee->id, $actor);
        }

        return $assigned->fresh() ?? $assigned;
    }

    public function unassign(Shift $shift, User $actor): Shift
    {
        $this->coverageReservationService->releaseForShift($shift);

        return DB::transaction(function () use ($shift) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

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

            $locked->update([
                'user_id' => null,
                'status' => 'draft',
            ]);

            return $locked->fresh() ?? $locked;
        });
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
