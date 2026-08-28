<?php

namespace App\Services;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ShiftHandoverService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const OUTSTANDING_MEDICATION_DUE_LABEL = 'Outstanding medications due from previous shift';

    private const CD_WITNESS_ATTEMPT_LIMIT = 5;

    private const CD_WITNESS_DECAY_SECONDS = 300;

    private const CD_WITNESS_FAILURE = 'The witness credential could not be verified.';

    /** @var array<int, string> */
    private const MUTATION_AUTHORIZATION_EVIDENCE = [
        'handovers.create',
        'handovers.viewAny',
        'shifts.manageAny',
        'shifts.update',
        'shifts.viewAssigned',
        'shifts.viewAny',
        'clients.update',
        'timesheets.create',
        'timesheets.manageAny',
        'timesheets.approve',
        'reports.viewAny',
        MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        'medications.controlled.witness',
        ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
    ];

    /** @var array<int, string> */
    private const WRITE_ACTION_PERMISSIONS = [
        'handovers.create',
        'shifts.update',
        'shifts.viewAssigned',
        'shifts.manageAny',
        'clients.update',
    ];

    /** @var array<int, string> */
    private const ACKNOWLEDGE_ACTION_PERMISSIONS = [
        'handovers.viewAny',
        'shifts.viewAny',
        'shifts.viewAssigned',
        'shifts.update',
        'shifts.manageAny',
        'clients.update',
    ];

    /** @var array<int, string> */
    private const COMPLETION_WAIVER_ACTION_PERMISSIONS = [
        'shifts.update',
        'shifts.viewAssigned',
        'shifts.manageAny',
        'timesheets.create',
        'timesheets.manageAny',
        'timesheets.approve',
    ];

    /** A presence edit-lock is "active" only this many seconds after it was taken. */
    public const EDIT_LOCK_TTL_SECONDS = 300;

    /**
     * Days the outgoing/assigned staff member may keep editing a posted handover
     * after the shift. Past this window it locks to managers only. Drafts stay
     * editable by their author regardless of age.
     */
    public const EDIT_WINDOW_DAYS = 7;

    public function __construct(
        protected ShiftTimelineService $timelineService,
        protected UserSiteAccessService $siteAccess,
        protected MedicationGovernanceScopeService $medicationGovernance,
        protected AuthorizationEvidenceLockService $authorizationEvidence,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int|null>  $additionalParticipantUserIds
     * @return array{handover: ShiftHandover, action: string}
     */
    public function save(
        Shift $outgoingShift,
        User $actor,
        array $data,
        array $additionalParticipantUserIds = [],
    ): array {
        $submit = (bool) ($data['submit'] ?? true);
        $outgoingShiftId = (int) $outgoingShift->getKey();
        abort_unless($outgoingShiftId > 0, 404);

        return DB::transaction(function () use (
            $outgoingShiftId,
            $actor,
            $data,
            $submit,
            $additionalParticipantUserIds,
        ) {
            $effectiveAt = now();
            $outgoingShiftSnapshot = Shift::query()
                ->whereKey($outgoingShiftId)
                ->first(['id', 'user_id']);
            $existingSnapshot = ShiftHandover::query()
                ->where('outgoing_shift_id', $outgoingShiftId)
                ->first(['id', 'incoming_shift_id', 'incoming_staff_id']);
            $hasIncomingShiftInput = array_key_exists('incoming_shift_id', $data);
            $plannedIncomingShiftId = $hasIncomingShiftInput
                ? $this->positiveIdOrNull($data['incoming_shift_id'])
                : $this->positiveIdOrNull($existingSnapshot?->incoming_shift_id);
            $plannedIncomingShift = $plannedIncomingShiftId !== null
                ? Shift::query()->whereKey($plannedIncomingShiftId)->first(['id', 'user_id'])
                : null;
            $hasIncomingStaffInput = array_key_exists('incoming_staff_id', $data);
            $plannedIncomingStaffId = $hasIncomingStaffInput
                ? $this->positiveIdOrNull($data['incoming_staff_id'])
                : ($hasIncomingShiftInput
                    ? $this->positiveIdOrNull($plannedIncomingShift?->user_id)
                    : $this->positiveIdOrNull(
                        $plannedIncomingShift?->user_id ?? $existingSnapshot?->incoming_staff_id,
                    ));
            $submittedCdWitnessId = $this->positiveIdOrNull(
                data_get($data, 'cd_verification_input.witness_id'),
            );
            $plannedParticipantIds = collect([
                (int) $actor->id,
                $this->positiveIdOrNull($outgoingShiftSnapshot?->user_id),
                $plannedIncomingStaffId,
                $this->positiveIdOrNull($existingSnapshot?->incoming_staff_id),
                $submittedCdWitnessId,
                ...$additionalParticipantUserIds,
            ])
                ->filter(fn (?int $id): bool => $id !== null)
                ->map(fn (int $id): int => $id)
                ->unique()
                ->sort()
                ->values();
            [$client, $outgoingShift, $lockedPresenceShifts] = $this->lockCanonicalOutgoingContext(
                $outgoingShiftId,
                $data['client_id'] ?? null,
                $plannedParticipantIds->all(),
                [
                    $plannedIncomingShiftId,
                    $this->positiveIdOrNull($existingSnapshot?->incoming_shift_id),
                ],
                $effectiveAt,
            );
            abort_unless(
                $plannedParticipantIds->contains((int) $outgoingShift->user_id),
                409,
                'This handover changed while it was being opened. Reload it before saving.',
            );

            // The outgoing Shift row is the aggregate mutex. Once held, re-resolve
            // the one durable handover identity; never trust a pre-transaction
            // "no draft exists" snapshot when two first-save requests race.
            $existing = ShiftHandover::query()
                ->where('outgoing_shift_id', $outgoingShift->id)
                ->lockForUpdate()
                ->first();
            $isFirstSave = $existing === null;

            if ($existing !== null) {
                abort_unless(
                    (int) $existing->client_id === (int) $client->id
                    && (int) $existing->outgoing_staff_id === (int) $outgoingShift->user_id,
                    404,
                );
                $existing->loadMissing('outgoingStaff:id,name');

                if (($data['replace_owned_draft'] ?? false) === true
                    && in_array($existing->status, [self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED], true)) {
                    [$actor] = $this->lockCurrentHandoverAuthority(
                        $client,
                        $outgoingShift,
                        $actor,
                        self::WRITE_ACTION_PERMISSIONS,
                        $plannedParticipantIds->all(),
                    );

                    // Submission may win the race after AttendanceService's
                    // no-op preflight. Preserve that terminal evidence and let
                    // the routine clock-out continue without rewriting it.
                    return [
                        'handover' => $this->freshHandover($existing),
                        'action' => 'submitted',
                    ];
                }
            }

            // Resolve the incoming Shift only from the frozen sorted Shift union,
            // then acquire every involved User in one batch. No later witness
            // lookup may introduce a Shift lock behind those User rows.
            $incomingShift = $this->resolveIncomingShift(
                $outgoingShift,
                $hasIncomingShiftInput ? $data['incoming_shift_id'] : $existing?->incoming_shift_id,
                lockedShifts: $lockedPresenceShifts,
            );
            $incomingStaffId = $this->resolveIncomingStaffId(
                $outgoingShift,
                $incomingShift,
                $hasIncomingStaffInput
                    ? $data['incoming_staff_id']
                    : ($hasIncomingShiftInput ? null : $existing?->incoming_staff_id),
                (int) $client->site_id,
                verifyCurrentStaff: false,
            );
            abort_unless(
                $incomingStaffId === null || $plannedParticipantIds->contains($incomingStaffId),
                409,
                'This handover changed while it was being opened. Reload it before saving.',
            );
            [$actor, $lockedUsers] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::WRITE_ACTION_PERMISSIONS,
                $plannedParticipantIds->all(),
                [$incomingStaffId, $submittedCdWitnessId],
            );
            $this->assertControlledMedicationDueAuthority($actor, $data);

            $existingCdVerification = is_array($existing?->cd_verification)
                ? $existing->cd_verification
                : null;
            $cdResolution = $this->resolveCdVerification(
                $data['cd_verification_input'] ?? null,
                $actor,
                $client,
                $existingCdVerification,
                $lockedUsers,
                $effectiveAt,
                $lockedPresenceShifts,
            );

            // Exact "controlled drugs are in play for this client" flag, so the eMAR
            // "CD count unverified" alert is precise without an index-time per-row
            // query. One cheap existence check per save.
            $cdRequired = ClientMedication::query()
                ->where('client_id', $client->id)
                ->active()
                ->controlled()
                ->exists();

            $attributes = [
                'outgoing_shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $incomingShift?->id,
                'client_id' => $client->id,
                'outgoing_staff_id' => $outgoingShift->user_id,
                'incoming_staff_id' => $incomingStaffId,
                'handover_notes' => (string) $data['handover_notes'],
                'client_mood' => $data['client_mood'] ?? null,
                'tasks_pending' => $this->normalizeStructuredItems($data['tasks_pending'] ?? null)
                    ?? $outgoingShift->tasks
                        ->where('is_completed', false)
                        ->map(fn ($task) => ['id' => $task->id, 'label' => $task->label])
                        ->values()
                        ->all(),
                'medications_due' => array_key_exists('medications_due', $data)
                    ? $this->normalizeStructuredItems($data['medications_due'])
                    : $existing?->medications_due,
                'incidents_to_note' => $this->normalizeStructuredItems($data['incidents_to_note'] ?? null)
                    ?? $outgoingShift->incidents
                        ->map(fn ($incident) => [
                            'id' => $incident->id,
                            'type' => $incident->type,
                            'severity' => $incident->severity,
                            'status' => $incident->status,
                            'occurred_at' => optional($incident->occurred_at)->toISOString(),
                        ])
                        ->values()
                        ->all(),
                'follow_up_items' => $this->normalizeStructuredItems($data['follow_up_items'] ?? null),
                'observations_summary' => $this->buildObservationsSummary($outgoingShift),
                'cd_verification' => $cdResolution['verification'],
                'cd_required' => $cdRequired,
                // Saving releases the presence edit-lock — the editor is done.
                'locked_by' => null,
                'locked_at' => null,
                'status' => self::STATUS_DRAFT,
            ];
            if ($existing !== null) {
                if ($existing->status === self::STATUS_ACKNOWLEDGED) {
                    throw ValidationException::withMessages([
                        'handover' => 'This shift handover has already been acknowledged and cannot be replaced.',
                    ]);
                }

                $expectedVersion = $data['expected_version'] ?? null;
                if (($data['replace_owned_draft'] ?? false) === true
                    && ! $submit
                    && $existing->status === self::STATUS_DRAFT) {
                    // The outgoing Shift and its sole handover are locked here,
                    // so a clock-out refresh can explicitly target the current
                    // owned draft without trusting a stale preflight read.
                    $expectedVersion = (int) $existing->version;
                }
                if ($expectedVersion === null) {
                    if ($this->firstSaveRetryMatches($existing, $attributes, $submit)) {
                        return [
                            'handover' => $this->freshHandover($existing),
                            'action' => $submit ? 'submitted' : 'draft_saved',
                        ];
                    }

                    throw ValidationException::withMessages([
                        'handover' => 'This handover already exists. Reload it before applying another change.',
                    ]);
                }

                if ($existing->status === self::STATUS_SUBMITTED) {
                    throw ValidationException::withMessages([
                        'handover' => 'A submitted handover already exists for this shift.',
                    ]);
                }

                abort_unless($existing->status === self::STATUS_DRAFT, 404);
                if ((int) $existing->version !== (int) $expectedVersion) {
                    throw ValidationException::withMessages([
                        'handover' => sprintf(
                            'This handover was changed by %s after you opened it. Reload to see their version, then re-apply your edits.',
                            $existing->outgoingStaff?->name ?? 'another worker',
                        ),
                    ]);
                }
            }

            $handover = $existing ?? new ShiftHandover;
            $attributes['version'] = $existing ? (int) $existing->version + 1 : 1;
            $handover->fill($attributes);

            $handover->save();

            $freshHandover = $handover->fresh([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
            ]) ?? $handover;

            if ($isFirstSave) {
                $this->timelineService->recordHandoverCreated($freshHandover, $outgoingShift, $actor);
            } else {
                $this->refreshHandoverTimelineSnapshot($freshHandover);
            }
            if ($cdResolution['audit_action'] !== null) {
                AuditLogger::logOrFail(
                    'shift.handover.cdVerification.'.$cdResolution['audit_action'],
                    $freshHandover,
                    [
                        'actor_id' => (int) $actor->id,
                        'client_id' => (int) $client->id,
                        'shift_id' => (int) $outgoingShift->id,
                        'verification_result' => $cdResolution['verification']['result'],
                        'witness_id' => (int) $cdResolution['verification']['witness_id'],
                        'witness_attestation' => $cdResolution['verification']['witness_attestation'],
                        'previous_verification' => $existingCdVerification,
                        'replacement_verification' => $cdResolution['verification'],
                    ],
                );
            }
            AuditLogger::logOrFail($isFirstSave ? 'shift.handover.created' : 'shift.handover.updated', $freshHandover, [
                'shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $freshHandover->incoming_shift_id,
                'status' => $freshHandover->status,
            ]);

            if (! $submit) {
                return [
                    'handover' => $freshHandover,
                    'action' => 'draft_saved',
                ];
            }

            $submitted = $this->submit($freshHandover, $actor);

            return [
                'handover' => $submitted,
                'action' => 'submitted',
            ];
        });
    }

    public function submit(ShiftHandover $handover, User $actor): ShiftHandover
    {
        return DB::transaction(function () use ($handover, $actor) {
            [$client, $outgoingShift, $handover] = $this->lockCanonicalHandoverAggregate((int) $handover->id);

            if (! in_array($handover->status, [
                self::STATUS_DRAFT,
                self::STATUS_SUBMITTED,
                self::STATUS_ACKNOWLEDGED,
            ], true)) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::WRITE_ACTION_PERMISSIONS,
                );
                abort(404);
            }

            if ($handover->status === self::STATUS_ACKNOWLEDGED) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::WRITE_ACTION_PERMISSIONS,
                );
                throw ValidationException::withMessages([
                    'handover' => 'Acknowledged handovers cannot be resubmitted.',
                ]);
            }

            if ($handover->status === self::STATUS_SUBMITTED) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::WRITE_ACTION_PERMISSIONS,
                );

                return $this->freshHandover($handover);
            }

            if (! $handover->incoming_shift_id) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::WRITE_ACTION_PERMISSIONS,
                );
                abort_unless($handover->status === self::STATUS_DRAFT, 404);
                throw ValidationException::withMessages([
                    'incoming_shift_id' => 'Assign the exact incoming Shift before submitting this handover.',
                ]);
            }

            abort_unless($handover->status === self::STATUS_DRAFT, 404);
            $matchedIncoming = $this->resolveIncomingShift(
                $outgoingShift,
                $handover->incoming_shift_id,
                true,
            );
            $incomingStaffId = $this->resolveIncomingStaffId(
                $outgoingShift,
                $matchedIncoming,
                $handover->incoming_staff_id,
                (int) $client->site_id,
                verifyCurrentStaff: false,
            );
            [$actor] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::WRITE_ACTION_PERMISSIONS,
                [$incomingStaffId],
                [$incomingStaffId],
            );
            abort_unless($this->canSubmit($handover, $actor), 403);
            if ($incomingStaffId === null) {
                throw ValidationException::withMessages([
                    'incoming_shift_id' => 'Assign a current incoming worker before submitting this handover.',
                ]);
            }

            $handover->forceFill([
                'incoming_shift_id' => $matchedIncoming?->id,
                'incoming_staff_id' => $incomingStaffId,
                'status' => self::STATUS_SUBMITTED,
                'submitted_at' => $handover->submitted_at ?? now(),
                'submitted_by' => $actor->id,
            ])->save();

            $fresh = $this->freshHandover($handover);

            $this->timelineService->recordHandoverSubmitted($fresh, $fresh->outgoingShift, $actor);
            AuditLogger::logOrFail('shift.handover.submitted', $fresh, [
                'shift_id' => $fresh->outgoing_shift_id,
                'incoming_shift_id' => $fresh->incoming_shift_id,
            ]);

            return $fresh;
        });
    }

    public function acknowledge(ShiftHandover $handover, User $actor): ShiftHandover
    {
        return DB::transaction(function () use ($handover, $actor) {
            [$client, $outgoingShift, $handover] = $this->lockCanonicalHandoverAggregate((int) $handover->id);

            if ($handover->status === self::STATUS_DRAFT) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::ACKNOWLEDGE_ACTION_PERMISSIONS,
                    requireWriteAuthority: false,
                );
                throw ValidationException::withMessages([
                    'handover' => 'Draft handovers must be submitted before they can be acknowledged.',
                ]);
            }

            if ($handover->status === self::STATUS_ACKNOWLEDGED) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::ACKNOWLEDGE_ACTION_PERMISSIONS,
                    requireWriteAuthority: false,
                );

                return $this->freshHandover($handover);
            }

            if ($handover->status !== self::STATUS_SUBMITTED) {
                [$actor] = $this->lockCurrentHandoverAuthority(
                    $client,
                    $outgoingShift,
                    $actor,
                    self::ACKNOWLEDGE_ACTION_PERMISSIONS,
                    requireWriteAuthority: false,
                );
                abort(404);
            }
            $incomingShift = $this->resolveIncomingShift(
                $outgoingShift,
                $handover->incoming_shift_id,
                true,
            );
            $incomingStaffId = $this->resolveIncomingStaffId(
                $outgoingShift,
                $incomingShift,
                null,
                (int) $client->site_id,
                verifyCurrentStaff: false,
            );
            [$actor] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::ACKNOWLEDGE_ACTION_PERMISSIONS,
                [$incomingStaffId],
                [$incomingStaffId],
                requireWriteAuthority: false,
            );
            // Preserve incoming_staff_id as the immutable submit-time recipient
            // snapshot. Acknowledgement authority follows the Shift's current
            // assignee resolved above while both aggregate rows remain locked.
            $handover->setRelation('incomingShift', $incomingShift);
            $handover->unsetRelation('incomingStaff');
            $this->assertAcknowledgementTargetStillValid($handover);
            abort_unless($this->canAcknowledge($handover, $actor), 404);

            $submittedIncomingStaffId = $handover->incoming_staff_id
                ? (int) $handover->incoming_staff_id
                : null;
            $assignmentChanged = $submittedIncomingStaffId !== $incomingStaffId;
            if ($assignmentChanged) {
                AuditLogger::logOrFail('shift.handover.incomingAssignment.rebound', $handover, [
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'shift_id' => (int) $outgoingShift->id,
                    'incoming_shift_id' => (int) $incomingShift->id,
                    'submitted_incoming_staff_id' => $submittedIncomingStaffId,
                    'current_incoming_staff_id' => $incomingStaffId,
                ]);
            }

            $handover->forceFill([
                'status' => self::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => now(),
                'acknowledged_by' => $actor->id,
            ])->save();

            $fresh = $this->freshHandover($handover, includeAcknowledger: true);

            $this->timelineService->recordHandoverAcknowledged($fresh, $fresh->outgoingShift, $actor);
            AuditLogger::logOrFail('shift.handover.acknowledged', $fresh, [
                'shift_id' => $fresh->outgoing_shift_id,
                'incoming_shift_id' => $fresh->incoming_shift_id,
                'submitted_incoming_staff_id' => $submittedIncomingStaffId,
                'acknowledging_incoming_staff_id' => $incomingStaffId,
                'incoming_assignment_changed' => $assignmentChanged,
            ]);

            return $fresh;
        });
    }

    /**
     * Delete a draft through the same canonical aggregate lock order used by
     * save/submit/acknowledge. This makes a concurrent terminal transition win
     * or lose before the draft-state and actor-authority checks are evaluated.
     */
    public function destroyDraft(ShiftHandover $handover, User $actor): void
    {
        DB::transaction(function () use ($handover, $actor): void {
            [$client, $outgoingShift, $lockedHandover] = $this->lockCanonicalHandoverAggregate((int) $handover->id);
            [$actor] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::WRITE_ACTION_PERMISSIONS,
            );
            abort_unless($this->canSubmit($lockedHandover, $actor), 403);
            abort_unless(
                $lockedHandover->status === self::STATUS_DRAFT,
                422,
                'Only draft handovers can be deleted.',
            );

            if (is_array($lockedHandover->cd_verification)) {
                throw ValidationException::withMessages([
                    'handover' => 'A draft with witnessed controlled-drug evidence must be retained. Submit it or record a governed replacement.',
                ]);
            }

            AuditLogger::logOrFail('shift.handover.deleted', $lockedHandover, [
                'actor_id' => (int) $actor->id,
                'client_id' => (int) $lockedHandover->client_id,
                'shift_id' => (int) $lockedHandover->outgoing_shift_id,
                'status' => $lockedHandover->status,
            ]);
            TimelineEvent::query()
                ->where('source_type', ShiftHandover::class)
                ->where('source_id', $lockedHandover->id)
                ->delete();

            $lockedHandover->delete();
        });
    }

    /**
     * Try to take the presence edit-lock. Returns null when acquired (or already
     * held by this actor); returns the current holder's name when another worker
     * holds a still-active lock, so the UI can say "being edited by X".
     */
    public function acquireEditLock(ShiftHandover $handover, User $actor): ?string
    {
        return DB::transaction(function () use ($handover, $actor) {
            [$client, $outgoingShift, $handover] = $this->lockCanonicalHandoverAggregate((int) $handover->id);
            [$actor] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::WRITE_ACTION_PERMISSIONS,
            );
            abort_unless($handover->status === self::STATUS_DRAFT, 422, 'Only draft handovers can be edited.');
            abort_unless($this->editPermission($handover, $actor)['editable'], 403);
            $holder = $this->activeLockHolder($handover, $actor->id);

            if ($holder !== null) {
                return $holder->name;
            }

            $handover->forceFill(['locked_by' => $actor->id, 'locked_at' => now()])->save();

            return null;
        });
    }

    /** Release the presence edit-lock only when this actor still holds it. */
    public function releaseEditLock(ShiftHandover $handover, User $actor): void
    {
        DB::transaction(function () use ($handover, $actor): void {
            [$client, $outgoingShift, $lockedHandover] = $this->lockCanonicalHandoverAggregate((int) $handover->id);
            [$actor] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::WRITE_ACTION_PERMISSIONS,
                requireWriteAuthority: false,
            );

            if ((int) $lockedHandover->locked_by !== (int) $actor->id) {
                return;
            }

            $lockedHandover->forceFill(['locked_by' => null, 'locked_at' => null])->save();
        });
    }

    /**
     * The user holding a still-active (within TTL) edit-lock, excluding the given
     * viewer. Null when unlocked, expired, or held by the viewer themselves.
     */
    public function activeLockHolder(ShiftHandover $handover, ?int $viewerId = null): ?User
    {
        if ($handover->locked_by === null || $handover->locked_at === null) {
            return null;
        }

        if ($handover->locked_at->lt(now()->subSeconds(self::EDIT_LOCK_TTL_SECONDS))) {
            return null;
        }

        if ($viewerId !== null && (int) $handover->locked_by === (int) $viewerId) {
            return null;
        }

        return $handover->lockedBy;
    }

    /**
     * @return array{
     *     requires_handover: bool,
     *     ambiguous: bool,
     *     matched_shift: Shift|null,
     *     candidate_ids: array<int, int>,
     *     matched_handover: ShiftHandover|null,
     *     reason: string
     * }
     */
    public function completionRequirement(
        Shift $shift,
        ?CarbonInterface $proposedActualEndAt = null,
        bool $lockForUpdate = false,
    ): array {
        if ($lockForUpdate && DB::transactionLevel() < 1) {
            throw new \LogicException('Completion handover locks require the governing Shift transaction.');
        }

        // Preserve the global handover lock order: Client/outgoing Shift are
        // already locked by lockCompletionRequirement(), then the durable
        // handover row is locked before any candidate incoming Shift rows.
        $lockedHandovers = $lockForUpdate
            ? ShiftHandover::query()
                ->where('outgoing_shift_id', $shift->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
            : null;

        $expectation = $this->resolveExpectedIncomingShift(
            $shift,
            $proposedActualEndAt,
            $lockForUpdate,
        );
        $matchedShift = $expectation['matched_shift'];

        $matchedHandover = null;
        if ($matchedShift) {
            if ($lockedHandovers !== null) {
                $matchedHandover = $lockedHandovers
                    ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED])
                    ->where('incoming_shift_id', $matchedShift->id)
                    ->sortByDesc('id')
                    ->first();
                if ($matchedHandover !== null
                    && ! $this->siteAccess->handoverHasIntrinsicIntegrity($matchedHandover)) {
                    $matchedHandover = null;
                }
            } else {
                $matchedHandover = ShiftHandover::query()
                    ->tap(fn (Builder $query) => $this->siteAccess->applyHandoverIntegrityScope($query))
                    ->where('outgoing_shift_id', $shift->id)
                    ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED])
                    ->where('incoming_shift_id', $matchedShift->id)
                    ->latest('submitted_at')
                    ->latest('id')
                    ->first();
            }
        }

        return [
            'requires_handover' => $matchedShift !== null || $expectation['ambiguous'],
            'ambiguous' => $expectation['ambiguous'],
            'matched_shift' => $matchedShift,
            'candidate_ids' => $expectation['candidate_ids'],
            'matched_handover' => $matchedHandover,
            'reason' => $matchedShift
                ? 'A relevant incoming shift exists, so a submitted handover or waiver reason is required before completion.'
                : ($expectation['ambiguous']
                    ? 'Multiple possible incoming shifts matched, so handover targeting was not inferred automatically.'
                    : 'No qualifying incoming shift was found.'),
        ];
    }

    /**
     * Lock the canonical completion aggregate and recompute its handover
     * requirement against the proposed actual finish while those rows remain
     * locked. The caller must already be inside its completion transaction.
     *
     * @return array{shift: Shift, requirement: array<string, mixed>}
     */
    public function lockCompletionRequirement(
        Shift $shift,
        CarbonInterface $proposedActualEndAt,
    ): array {
        $lockedShift = $this->lockCompletionShift($shift);

        return [
            'shift' => $lockedShift,
            'requirement' => $this->completionRequirement(
                $lockedShift,
                $proposedActualEndAt,
                true,
            ),
        ];
    }

    /**
     * Acquire the Client -> outgoing Shift portion of the canonical handover
     * lock order. Completion callers can then lock attendance/task/note
     * evidence before resolving and locking handover/incoming-Shift evidence.
     */
    public function lockCompletionShift(Shift $shift): Shift
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Shift completion scope must be locked inside a transaction.');
        }

        [, $lockedShift] = $this->lockCanonicalOutgoingContext((int) $shift->id);

        return $lockedShift;
    }

    public function recordCompletionWaiver(Shift $shift, User $actor, string $reason, array $requirement): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Shift completion waivers must be recorded inside the completion transaction.');
        }

        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();
        if (! $mutex) {
            throw new \LogicException('The application payroll mutex is missing; migration repair is required.');
        }

        [$client, $shift] = $this->lockCanonicalOutgoingContext((int) $shift->id);
        [$actor, $lockedUsers] = $this->lockCurrentHandoverAuthority(
            $client,
            $shift,
            $actor,
            self::COMPLETION_WAIVER_ACTION_PERMISSIONS,
            [(int) $shift->user_id],
            [(int) $shift->user_id],
            false,
            ['reports.viewAny'],
        );
        $assignee = $lockedUsers->get((int) $shift->user_id);
        $assigneeProfile = $assignee?->hrEmployeeProfile;
        abort_unless(
            (int) $shift->user_id === (int) $actor->id
                || $actor->canDo('shifts.manageAny')
                || $actor->canDo('timesheets.manageAny')
                || ($actor->canDo('timesheets.approve')
                    && $assigneeProfile instanceof HrEmployeeProfile
                    && (int) $assigneeProfile->manager_user_id === (int) $actor->id),
            403,
        );

        $shift->forceFill([
            'handover_waiver_reason' => $reason,
            'handover_waived_at' => now(),
            'handover_waived_by' => $actor->id,
        ])->save();

        $shift->loadMissing([
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'serviceContext:id,name,type',
            'staff:id,name',
        ]);

        $this->timelineService->recordHandoverWaived(
            $shift,
            $reason,
            $actor,
            $requirement['matched_shift'] ?? null,
            (bool) ($requirement['ambiguous'] ?? false),
        );

        AuditLogger::logOrFail('shift.handover.waived', $shift, [
            'actor_id' => (int) $actor->id,
            'shift_id' => $shift->id,
            'reason' => $reason,
            'matched_incoming_shift_id' => $requirement['matched_shift']?->id,
            'ambiguous_match' => (bool) ($requirement['ambiguous'] ?? false),
            'candidate_incoming_shift_ids' => array_values($requirement['candidate_ids'] ?? []),
        ]);
    }

    /**
     * @return array{
     *     matched_shift: Shift|null,
     *     ambiguous: bool,
     *     candidate_ids: array<int, int>
     * }
     */
    public function resolveExpectedIncomingShift(
        Shift $outgoingShift,
        ?CarbonInterface $proposedActualEndAt = null,
        bool $lockForUpdate = false,
    ): array {
        if ($lockForUpdate && DB::transactionLevel() < 1) {
            throw new \LogicException('Incoming Shift locks require the governing completion transaction.');
        }

        $outgoingShift->loadMissing([
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'serviceContext:id,name,type',
        ]);

        $windowStart = $proposedActualEndAt
            ?? $outgoingShift->actual_ends_at
            ?? $outgoingShift->ends_at;
        if ($windowStart === null) {
            return [
                'matched_shift' => null,
                'ambiguous' => false,
                'candidate_ids' => [],
            ];
        }
        $windowEnd = $windowStart->copy()->addHours(12);
        $siteId = $this->effectiveSiteId($outgoingShift);
        if (! $siteId) {
            return [
                'matched_shift' => null,
                'ambiguous' => false,
                'candidate_ids' => [],
            ];
        }

        $query = Shift::query()
            ->tap(fn (Builder $query) => $this->siteAccess->applyShiftIntegrityScope($query))
            ->with(['client:id,first_name,last_name,site_id', 'site:id,name,type', 'serviceContext:id,name,type', 'staff:id,name'])
            ->whereKeyNot($outgoingShift->id)
            ->where('site_id', $siteId)
            ->whereNotNull('client_id')
            ->whereHas('client', fn (Builder $client): Builder => $client->where('site_id', $siteId))
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotNull('user_id')
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($outgoingShift->client_id) {
            $query->where('client_id', $outgoingShift->client_id);
        }

        if ($outgoingShift->service_context_id) {
            $query->where('service_context_id', $outgoingShift->service_context_id);
        }

        $candidates = $lockForUpdate
            ? $query->reorder()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->sort(function (Shift $left, Shift $right): int {
                    $startsAt = $left->starts_at->getTimestamp() <=> $right->starts_at->getTimestamp();

                    return $startsAt !== 0 ? $startsAt : ((int) $left->id <=> (int) $right->id);
                })
                ->values()
            : $query->take(3)->get()->values();

        if ($candidates->isEmpty()) {
            return [
                'matched_shift' => null,
                'ambiguous' => false,
                'candidate_ids' => [],
            ];
        }

        if ($candidates->count() > 1 && optional($candidates[1]->starts_at)->equalTo($candidates[0]->starts_at)) {
            return [
                'matched_shift' => null,
                'ambiguous' => true,
                'candidate_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return [
            'matched_shift' => $candidates->first(),
            'ambiguous' => false,
            'candidate_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    public function canViewAny(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('handovers.viewAny')
            || $auth->canDo('shifts.viewAny')
            || $auth->canDo('shifts.manageAny')
        );
    }

    public function canAccessWorkflow(?User $auth): bool
    {
        return (bool) $auth && (
            $this->canViewAny($auth)
            || $auth->canDo('shifts.viewAssigned')
            || $auth->canDo('shifts.update')
            || $auth->canDo('handovers.create')
        );
    }

    /**
     * Resolve a create target before any target-sensitive request validation.
     * The transaction re-locks and rechecks the same tuple before writing.
     */
    public function writableOutgoingShift(User $actor, int $shiftId): Shift
    {
        abort_unless($shiftId > 0, 404);
        $siteIds = $this->siteAccess->accessibleSiteIds(
            $actor,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );

        return Shift::query()
            ->whereKey($shiftId)
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('client_id')
            ->whereNotNull('site_id')
            ->whereNotNull('user_id')
            ->where('status', '!=', 'cancelled')
            ->whereHas('client', fn (Builder $client): Builder => $client
                ->whereIn('site_id', $siteIds)
                ->whereColumn('clients.site_id', 'shifts.site_id'))
            ->when(
                ! $actor->canDo('shifts.manageAny'),
                fn (Builder $shift): Builder => $shift->where('user_id', $actor->id),
            )
            ->with(['tasks:id,shift_id,label,is_completed', 'incidents:id,shift_id,type,severity,status,occurred_at'])
            ->firstOrFail();
    }

    public function canSubmit(ShiftHandover $handover, ?User $auth): bool
    {
        if (! $auth) {
            return false;
        }

        if ($auth->canDo('shifts.manageAny')) {
            return true;
        }

        return in_array($handover->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED], true)
            && (int) $handover->outgoing_staff_id === (int) $auth->id;
    }

    public function canAcknowledge(ShiftHandover $handover, ?User $auth): bool
    {
        if (! $auth) {
            return false;
        }

        if ($handover->status !== self::STATUS_SUBMITTED || ! $handover->incoming_shift_id) {
            return false;
        }

        $incomingUserId = $this->currentIncomingStaffId($handover);

        return $incomingUserId !== null
            && (int) $incomingUserId === (int) $auth->id
            && ($auth->canDo('shifts.update') || $auth->canDo('shifts.viewAssigned'));
    }

    public function relatedToUser(ShiftHandover $handover, User $auth): bool
    {
        $incomingUserId = $this->currentIncomingStaffId($handover);

        return in_array((int) $auth->id, array_filter([
            (int) $handover->outgoing_staff_id,
            $incomingUserId ? (int) $incomingUserId : null,
            $handover->outgoingShift?->user_id ? (int) $handover->outgoingShift?->user_id : null,
            $handover->incomingShift?->user_id ? (int) $handover->incomingShift?->user_id : null,
        ], fn ($value) => $value !== null), true);
    }

    /**
     * Editability of a handover for a given user. Managers (handover/shift
     * oversight) can always edit; the outgoing/assigned author can edit a draft
     * indefinitely and a posted handover for EDIT_WINDOW_DAYS after the shift.
     * After that it locks to managers only. Mirrors the prototype's editLock().
     *
     * @return array{editable: bool, locked: bool, reason: string, days_left: int|null, age_days: int|null}
     */
    public function editPermission(ShiftHandover $handover, ?User $auth): array
    {
        if (! $auth) {
            return ['editable' => false, 'locked' => true, 'reason' => 'unauthenticated', 'days_left' => null, 'age_days' => null];
        }

        if ($handover->status !== self::STATUS_DRAFT) {
            return ['editable' => false, 'locked' => true, 'reason' => 'posted_immutable', 'days_left' => null, 'age_days' => null];
        }

        if ($auth->canDo('shifts.manageAny')) {
            return ['editable' => true, 'locked' => false, 'reason' => 'manager', 'days_left' => null, 'age_days' => null];
        }

        $isOwner = (int) $handover->outgoing_staff_id === (int) $auth->id
            || (int) ($handover->outgoingShift?->user_id ?? 0) === (int) $auth->id;

        if (! $isOwner) {
            return ['editable' => false, 'locked' => true, 'reason' => 'not_owner', 'days_left' => null, 'age_days' => null];
        }

        return ['editable' => true, 'locked' => false, 'reason' => 'draft', 'days_left' => null, 'age_days' => null];
    }

    /**
     * Apply a versioned content edit to a draft. Submitted and acknowledged
     * evidence is immutable; later change requires a separately governed
     * amendment workflow.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyEdit(ShiftHandover $handover, User $actor, array $data): ShiftHandover
    {
        return DB::transaction(function () use ($handover, $actor, $data) {
            $effectiveAt = now();
            $handoverSnapshot = ShiftHandover::query()
                ->whereKey((int) $handover->id)
                ->first([
                    'id',
                    'client_id',
                    'outgoing_shift_id',
                    'outgoing_staff_id',
                    'incoming_shift_id',
                    'incoming_staff_id',
                ]);
            abort_unless($handoverSnapshot !== null, 404);
            $hasIncomingShiftInput = array_key_exists('incoming_shift_id', $data);
            $plannedIncomingShiftId = $hasIncomingShiftInput
                ? $this->positiveIdOrNull($data['incoming_shift_id'])
                : $this->positiveIdOrNull($handoverSnapshot->incoming_shift_id);
            $plannedIncomingShift = $plannedIncomingShiftId !== null
                ? Shift::query()->whereKey($plannedIncomingShiftId)->first(['id', 'user_id'])
                : null;
            $hasIncomingStaffInput = array_key_exists('incoming_staff_id', $data);
            $plannedIncomingStaffId = $hasIncomingStaffInput
                ? $this->positiveIdOrNull($data['incoming_staff_id'])
                : ($hasIncomingShiftInput
                    ? $this->positiveIdOrNull($plannedIncomingShift?->user_id)
                    : $this->positiveIdOrNull(
                        $plannedIncomingShift?->user_id ?? $handoverSnapshot->incoming_staff_id,
                    ));
            $submittedCdWitnessId = $this->positiveIdOrNull(
                data_get($data, 'cd_verification_input.witness_id'),
            );
            $plannedParticipantIds = collect([
                (int) $actor->id,
                $plannedIncomingStaffId,
                $this->positiveIdOrNull($handoverSnapshot->incoming_staff_id),
                $submittedCdWitnessId,
            ])
                ->filter(fn (?int $id): bool => $id !== null)
                ->map(fn (int $id): int => $id)
                ->unique()
                ->sort()
                ->values();
            [$client, $outgoingShift, $lockedPresenceShifts] = $this->lockCanonicalOutgoingContext(
                (int) $handoverSnapshot->outgoing_shift_id,
                $handoverSnapshot->client_id,
                $plannedParticipantIds->all(),
                [
                    $plannedIncomingShiftId,
                    $this->positiveIdOrNull($handoverSnapshot->incoming_shift_id),
                ],
                $effectiveAt,
            );
            $handover = $this->lockCanonicalHandoverRow(
                $handoverSnapshot,
                $client,
                $outgoingShift,
            );
            $incomingShift = $this->resolveIncomingShift(
                $outgoingShift,
                $hasIncomingShiftInput ? ($data['incoming_shift_id'] ?? null) : $handover->incoming_shift_id,
                lockedShifts: $lockedPresenceShifts,
            );
            $incomingStaffId = $this->resolveIncomingStaffId(
                $outgoingShift,
                $incomingShift,
                $hasIncomingStaffInput
                    ? $data['incoming_staff_id']
                    : ($hasIncomingShiftInput ? null : $handover->incoming_staff_id),
                (int) $client->site_id,
                verifyCurrentStaff: false,
            );
            abort_unless(
                $incomingStaffId === null || $plannedParticipantIds->contains($incomingStaffId),
                409,
                'This handover changed while it was being opened. Reload it before saving.',
            );
            [$actor, $lockedUsers] = $this->lockCurrentHandoverAuthority(
                $client,
                $outgoingShift,
                $actor,
                self::WRITE_ACTION_PERMISSIONS,
                [$incomingStaffId, $submittedCdWitnessId],
                [$incomingStaffId, $submittedCdWitnessId],
            );
            $this->assertControlledMedicationDueAuthority($actor, $data);
            abort_unless($handover->status === self::STATUS_DRAFT, 422, 'Submitted handovers are immutable.');
            $edit = $this->editPermission($handover, $actor);
            abort_unless($edit['editable'], 403);
            $expectedVersion = $data['expected_version'] ?? null;
            if ($expectedVersion === null || (int) $expectedVersion !== (int) $handover->version) {
                throw ValidationException::withMessages([
                    'handover' => 'This handover changed after it was opened. Reload it before applying another edit.',
                ]);
            }
            $existingCdVerification = is_array($handover->cd_verification)
                ? $handover->cd_verification
                : null;
            $cdResolution = $this->resolveCdVerification(
                $data['cd_verification_input'] ?? null,
                $actor,
                $client,
                $existingCdVerification,
                $lockedUsers,
                $effectiveAt,
                $lockedPresenceShifts,
            );

            $attributes = [
                'handover_notes' => (string) $data['handover_notes'],
                'client_mood' => $data['client_mood'] ?? null,
                'incoming_shift_id' => $incomingShift?->id,
                'incoming_staff_id' => $incomingStaffId,
                'cd_verification' => $cdResolution['verification'],
                'version' => (int) $handover->version + 1,
                'locked_by' => null,
                'locked_at' => null,
            ];

            foreach (['medications_due', 'incidents_to_note', 'follow_up_items', 'tasks_pending'] as $listKey) {
                if (array_key_exists($listKey, $data)) {
                    $attributes[$listKey] = $this->normalizeStructuredItems($data[$listKey]);
                }
            }
            $handover->fill($attributes)->save();

            $fresh = $handover->fresh([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
            ]) ?? $handover;

            if ($cdResolution['audit_action'] !== null) {
                AuditLogger::logOrFail(
                    'shift.handover.cdVerification.'.$cdResolution['audit_action'],
                    $fresh,
                    [
                        'actor_id' => (int) $actor->id,
                        'client_id' => (int) $client->id,
                        'shift_id' => (int) $outgoingShift->id,
                        'verification_result' => $cdResolution['verification']['result'],
                        'witness_id' => (int) $cdResolution['verification']['witness_id'],
                        'witness_attestation' => $cdResolution['verification']['witness_attestation'],
                        'previous_verification' => $existingCdVerification,
                        'replacement_verification' => $cdResolution['verification'],
                    ],
                );
            }
            AuditLogger::logOrFail('shift.handover.updated', $fresh, [
                'shift_id' => $fresh->outgoing_shift_id,
                'status' => $fresh->status,
            ]);

            // Keep the client-timeline snapshot (mood/notes) in step with this
            // content edit. Done before the submit branch so a draft's created
            // event picks up the new content even when the edit also submits —
            // submit() then writes the submitted event itself.
            $this->refreshHandoverTimelineSnapshot($fresh);

            if (($data['submit'] ?? false) && $fresh->status === self::STATUS_DRAFT) {
                return $this->submit($fresh, $actor);
            }

            return $fresh;
        });
    }

    /**
     * Refresh the client-timeline snapshot after a content edit. The mood/notes a
     * viewer sees are embedded in the handover-created (draft) and
     * handover-submitted event bodies; the recordHandover* upserts are keyed on
     * (type, ShiftHandover, source_id), so they update those events in place. No
     * actor/time is passed, so the original attribution and timeline position are
     * preserved. The acknowledged event body carries no editable snapshot content,
     * so it is intentionally left untouched.
     */
    protected function refreshHandoverTimelineSnapshot(ShiftHandover $handover): void
    {
        $shift = $handover->outgoingShift;

        if (! $shift) {
            return;
        }

        $this->timelineService->recordHandoverCreated($handover, $shift);

        if (in_array($handover->status, [self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED], true)) {
            $this->timelineService->recordHandoverSubmitted($handover, $shift);
        }
    }

    /**
     * When presence users are supplied, the explicit outgoing/incoming Shifts
     * and every qualifying presence Shift are acquired in one ascending query.
     * That query is the transaction's first Shift lock and precedes all User,
     * RBAC, employment, and witness evidence locks.
     *
     * @param  array<int, int>|null  $presenceUserIds
     * @param  array<int, int|null>  $additionalShiftIds
     * @return array{0: Client, 1: Shift, 2?: Collection<int, Shift>}
     */
    protected function lockCanonicalOutgoingContext(
        int $outgoingShiftId,
        mixed $requestedClientId = null,
        ?array $presenceUserIds = null,
        array $additionalShiftIds = [],
        ?CarbonInterface $effectiveAt = null,
    ): array {
        $shiftSnapshot = Shift::query()
            ->whereKey($outgoingShiftId)
            ->first(['id', 'client_id', 'site_id']);
        abort_unless(
            $shiftSnapshot !== null
            && is_numeric($shiftSnapshot->client_id)
            && is_numeric($shiftSnapshot->site_id)
            && (int) $shiftSnapshot->client_id > 0
            && (int) $shiftSnapshot->site_id > 0,
            404,
        );

        $clientId = $requestedClientId !== null
            ? (int) $requestedClientId
            : (int) $shiftSnapshot->client_id;
        abort_unless($clientId === (int) $shiftSnapshot->client_id, 404);

        // The unlocked snapshot only tells us which Client to lock. The
        // constrained Shift re-read (or sorted Shift union) detects reassignment.
        $client = Client::query()
            ->whereKey($clientId)
            ->where('site_id', $shiftSnapshot->site_id)
            ->lockForUpdate()
            ->first();
        abort_unless($client !== null, 404);

        $lockedPresenceShifts = null;
        if ($presenceUserIds !== null) {
            if (! $effectiveAt instanceof CarbonInterface) {
                throw new \LogicException('The handover presence-Shift union requires one frozen effective moment.');
            }
            $lockedPresenceShifts = $this->medicationGovernance->lockControlledWitnessPresenceShifts(
                $presenceUserIds,
                (int) $client->site_id,
                $effectiveAt,
                [$outgoingShiftId, ...$additionalShiftIds],
            );
            $outgoingShift = $lockedPresenceShifts->get($outgoingShiftId);
            if ($outgoingShift instanceof Shift) {
                $outgoingShift->loadMissing('client:id,site_id');
            }
        } else {
            $outgoingShift = Shift::query()
                ->whereKey($shiftSnapshot->id)
                ->where('client_id', $client->id)
                ->where('site_id', $client->site_id)
                ->whereNotNull('user_id')
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->first();
        }
        abort_unless($outgoingShift !== null, 404);
        abort_unless(
            (int) $outgoingShift->id === (int) $shiftSnapshot->id
                && (int) $outgoingShift->client_id === (int) $client->id
                && (int) $outgoingShift->site_id === (int) $client->site_id
                && (int) $outgoingShift->user_id > 0
                && $outgoingShift->status !== 'cancelled',
            404,
        );
        $outgoingShift->loadMissing([
            'tasks:id,shift_id,label,is_completed',
            'incidents:id,shift_id,type,severity,status,occurred_at',
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'serviceContext:id,name,type',
            'staff:id,name',
        ]);

        return $lockedPresenceShifts instanceof Collection
            ? [$client, $outgoingShift, $lockedPresenceShifts]
            : [$client, $outgoingShift];
    }

    /**
     * @return array{0: Client, 1: Shift, 2: ShiftHandover}
     */
    protected function lockCanonicalHandoverAggregate(int $handoverId): array
    {
        abort_unless($handoverId > 0, 404);
        $snapshot = ShiftHandover::query()
            ->whereKey($handoverId)
            ->first(['id', 'client_id', 'outgoing_shift_id', 'outgoing_staff_id']);
        abort_unless(
            $snapshot !== null
            && (int) $snapshot->client_id > 0
            && (int) $snapshot->outgoing_shift_id > 0
            && (int) $snapshot->outgoing_staff_id > 0,
            404,
        );

        $client = Client::query()
            ->whereKey($snapshot->client_id)
            ->whereNotNull('site_id')
            ->lockForUpdate()
            ->first();
        abort_unless($client !== null, 404);

        $outgoingShift = Shift::query()
            ->whereKey($snapshot->outgoing_shift_id)
            ->where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->where('user_id', $snapshot->outgoing_staff_id)
            ->lockForUpdate()
            ->first();
        abort_unless($outgoingShift !== null, 404);

        $lockedHandover = ShiftHandover::query()
            ->whereKey($snapshot->id)
            ->where('client_id', $client->id)
            ->where('outgoing_shift_id', $outgoingShift->id)
            ->where('outgoing_staff_id', $outgoingShift->user_id)
            ->lockForUpdate()
            ->first();
        abort_unless($lockedHandover !== null, 404);

        $outgoingShift->loadMissing([
            'tasks:id,shift_id,label,is_completed',
            'incidents:id,shift_id,type,severity,status,occurred_at',
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'serviceContext:id,name,type',
            'staff:id,name',
        ]);
        $lockedHandover->setRelation('outgoingShift', $outgoingShift);
        $lockedHandover->loadMissing(['outgoingStaff:id,name']);

        return [$client, $outgoingShift, $lockedHandover];
    }

    protected function lockCanonicalHandoverRow(
        ShiftHandover $snapshot,
        Client $client,
        Shift $outgoingShift,
    ): ShiftHandover {
        $lockedHandover = ShiftHandover::query()
            ->whereKey($snapshot->id)
            ->where('client_id', $client->id)
            ->where('outgoing_shift_id', $outgoingShift->id)
            ->where('outgoing_staff_id', $outgoingShift->user_id)
            ->lockForUpdate()
            ->first();
        abort_unless($lockedHandover !== null, 404);
        $lockedHandover->setRelation('outgoingShift', $outgoingShift);
        $lockedHandover->loadMissing(['outgoingStaff:id,name']);

        return $lockedHandover;
    }

    /**
     * Join the canonical handover aggregate to the exact current User/RBAC,
     * employment, and Site evidence that authorizes its mutation. Every known
     * participant must be supplied on the first call so concurrent actor/witness
     * requests cannot invert User locks.
     *
     * @param  array<int, string>  $requiredActionPermissions
     * @param  array<int, int|null>  $participantUserIds
     * @param  array<int, int|null>  $siteBoundUserIds
     * @return array{0: User, 1: Collection<int, User>}
     */
    protected function lockCurrentHandoverAuthority(
        Client $client,
        Shift $outgoingShift,
        User $actor,
        array $requiredActionPermissions,
        array $participantUserIds = [],
        array $siteBoundUserIds = [],
        bool $requireWriteAuthority = true,
        array $siteBypassPermissions = MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
    ): array {
        abort_unless(DB::transactionLevel() > 0, 404);
        $siteId = (int) $client->site_id;
        abort_unless(
            $siteId > 0
                && (int) $outgoingShift->client_id === (int) $client->id
                && (int) $outgoingShift->site_id === $siteId,
            404,
        );

        $participantUserIds = collect($participantUserIds)
            ->map(fn ($userId): ?int => $this->positiveIdOrNull($userId))
            ->filter(fn (?int $userId): bool => $userId !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();
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
            ->values()
            ->all();
        $profileUserIds = collect([(int) $lockedActor->id, ...$siteBoundUserIds])
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

        abort_unless(collect($siteBoundUserIds)->every(function (int $userId) use ($profiles, $siteId): bool {
            $profile = $profiles->get($userId);

            return $profile instanceof HrEmployeeProfile
                && ((int) $profile->primary_site_id === $siteId
                    || collect($profile->secondary_site_ids ?? [])->contains(
                        fn ($candidate): bool => (int) $candidate === $siteId,
                    ));
        }), 404);

        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($site, 403, 'You are not authorized to access handovers for this site.');

        $assignedSiteIds = collect([
            $actorProfile->primary_site_id,
            ...($actorProfile->secondary_site_ids ?? []),
        ])
            ->filter(fn ($assignedSiteId): bool => is_numeric($assignedSiteId) && (int) $assignedSiteId > 0)
            ->map(fn ($assignedSiteId): int => (int) $assignedSiteId)
            ->unique()
            ->values()
            ->all();
        $canBypassSite = collect($siteBypassPermissions)
            ->contains(fn (string $permission): bool => $lockedActor->canDo($permission));
        abort_unless(
            $canBypassSite || in_array($siteId, $assignedSiteIds, true),
            403,
            'You are not authorized to access handovers for this site.',
        );
        abort_unless(
            collect($requiredActionPermissions)
                ->contains(fn (string $permission): bool => $lockedActor->canDo($permission)),
            403,
        );

        if ($requireWriteAuthority) {
            $this->assertActorCanWriteOutgoingHandover($outgoingShift, $lockedActor, $siteBypassPermissions);
        } else {
            $this->assertActorCanAccessOutgoingHandover($outgoingShift, $lockedActor, $siteBypassPermissions);
        }

        return [$lockedActor, $lockedUsers];
    }

    /** @param array<int, string> $siteBypassPermissions */
    protected function assertActorCanAccessOutgoingHandover(
        Shift $outgoingShift,
        User $actor,
        array $siteBypassPermissions = MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
    ): void {
        $this->siteAccess->assertCanAccessShift(
            $actor,
            $outgoingShift,
            $siteBypassPermissions,
            'You are not authorized to access handovers for this site.',
        );
    }

    /** @param array<int, string> $siteBypassPermissions */
    protected function assertActorCanWriteOutgoingHandover(
        Shift $outgoingShift,
        User $actor,
        array $siteBypassPermissions = MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
    ): void {
        $this->assertActorCanAccessOutgoingHandover($outgoingShift, $actor, $siteBypassPermissions);
        abort_unless(
            $actor->canDo('shifts.manageAny')
            || (int) $outgoingShift->user_id === (int) $actor->id,
            403,
        );
    }

    /**
     * The medications-due snapshot may contain controlled-medication identity
     * and schedule data. Any submitted value, including an explicit clear, is a
     * controlled write and therefore needs both exact controlled capabilities.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertControlledMedicationDueAuthority(User $actor, array $data): void
    {
        if (! array_key_exists('medications_due', $data)) {
            return;
        }

        abort_unless(
            $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );
    }

    protected function resolveIncomingShift(
        Shift $outgoingShift,
        mixed $incomingShiftId,
        bool $lockForUpdate = false,
        ?Collection $lockedShifts = null,
    ): ?Shift {
        if ($lockForUpdate && DB::transactionLevel() < 1) {
            throw new \LogicException('Incoming Shift locks require the governing handover transaction.');
        }

        if (! $incomingShiftId) {
            return null;
        }

        if ($lockedShifts instanceof Collection) {
            $incomingShift = $lockedShifts->get((int) $incomingShiftId);
            abort_unless(
                $incomingShift instanceof Shift
                    && (int) $incomingShift->client_id === (int) $outgoingShift->client_id
                    && (int) $incomingShift->site_id === (int) $this->effectiveSiteId($outgoingShift),
                404,
            );
            $incomingShift->loadMissing([
                'client:id,first_name,last_name,site_id',
                'site:id,name,type',
                'serviceContext:id,name,type',
                'staff:id,name',
            ]);
        } else {
            $query = Shift::query()
                ->with(['client:id,first_name,last_name,site_id', 'site:id,name,type', 'serviceContext:id,name,type', 'staff:id,name'])
                ->whereKey((int) $incomingShiftId)
                ->where('client_id', $outgoingShift->client_id)
                ->where('site_id', $this->effectiveSiteId($outgoingShift));
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            $incomingShift = $query->firstOrFail();
        }

        if (! $this->incomingShiftMatches($outgoingShift, $incomingShift)) {
            throw ValidationException::withMessages([
                'incoming_shift_id' => 'The selected incoming shift does not match the outgoing handover context.',
            ]);
        }

        return $incomingShift;
    }

    protected function resolveIncomingStaffId(
        Shift $outgoingShift,
        ?Shift $incomingShift,
        mixed $incomingStaffId,
        int $siteId,
        bool $verifyCurrentStaff = true,
    ): ?int {
        abort_unless((int) $this->effectiveSiteId($outgoingShift) === $siteId, 404);
        $providedStaffId = $incomingStaffId === null || $incomingStaffId === ''
            ? null
            : (int) $incomingStaffId;
        if ($providedStaffId !== null && $providedStaffId <= 0) {
            throw ValidationException::withMessages([
                'incoming_staff_id' => 'The selected incoming worker is invalid.',
            ]);
        }

        if ($incomingShift !== null) {
            $canonicalStaffId = $incomingShift->user_id ? (int) $incomingShift->user_id : null;
            if ($providedStaffId !== null && $providedStaffId !== $canonicalStaffId) {
                throw ValidationException::withMessages([
                    'incoming_staff_id' => 'The incoming worker must match the selected incoming Shift assignment.',
                ]);
            }

            if ($canonicalStaffId !== null && $verifyCurrentStaff) {
                $this->lockCurrentStaffAtSite($canonicalStaffId, $siteId);
            }

            return $canonicalStaffId;
        }

        if ($providedStaffId === null) {
            return null;
        }

        if ($verifyCurrentStaff) {
            $this->lockCurrentStaffAtSite($providedStaffId, $siteId);
        }

        return $providedStaffId;
    }

    protected function lockCurrentStaffAtSite(int $staffId, int $siteId): User
    {
        abort_unless(DB::transactionLevel() > 0 && $staffId > 0 && $siteId > 0, 404);
        $staff = User::query()
            ->staff()
            ->whereKey($staffId)
            ->whereNotNull('approved_at')
            ->lockForUpdate()
            ->first();
        abort_unless($staff !== null, 404);

        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $staff->id)
            ->active()
            ->atSite($siteId)
            ->where(function (Builder $dates) use ($today): void {
                $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function (Builder $dates) use ($today): void {
                $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->lockForUpdate()
            ->first();
        abort_unless($profile !== null, 404);

        return $staff;
    }

    protected function freshHandover(ShiftHandover $handover, bool $includeAcknowledger = false): ShiftHandover
    {
        $relations = [
            'outgoingShift.client:id,first_name,last_name,site_id',
            'outgoingShift.site:id,name,type',
            'outgoingShift.serviceContext:id,name,type',
            'outgoingShift.staff:id,name',
            'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
            'incomingStaff:id,name',
            'outgoingStaff:id,name',
        ];
        if ($includeAcknowledger) {
            $relations[] = 'acknowledger:id,name';
        }

        return $handover->fresh($relations) ?? $handover;
    }

    protected function incomingShiftMatches(Shift $outgoingShift, Shift $incomingShift): bool
    {
        if ((int) $incomingShift->id === (int) $outgoingShift->id) {
            return false;
        }

        if (! in_array($incomingShift->status, ['scheduled', 'in_progress'], true)
            || ! $incomingShift->user_id
            || ! $incomingShift->starts_at) {
            return false;
        }

        if (
            ! $incomingShift->client_id
            || ! $incomingShift->client
            || ! $incomingShift->client->site_id
            || ($incomingShift->site_id && (int) $incomingShift->site_id !== (int) $incomingShift->client->site_id)
        ) {
            return false;
        }

        if ($outgoingShift->client_id && (int) $incomingShift->client_id !== (int) $outgoingShift->client_id) {
            return false;
        }

        if ($outgoingShift->service_context_id && (int) $incomingShift->service_context_id !== (int) $outgoingShift->service_context_id) {
            return false;
        }

        $outgoingSiteId = $this->effectiveSiteId($outgoingShift);
        $incomingSiteId = $this->effectiveSiteId($incomingShift);

        if ($outgoingSiteId && $incomingSiteId && $outgoingSiteId !== $incomingSiteId) {
            return false;
        }

        $handoffBoundary = $outgoingShift->actual_ends_at ?? $outgoingShift->ends_at;
        if ($handoffBoundary === null
            || $incomingShift->starts_at->lessThan($handoffBoundary)
            || $incomingShift->starts_at->greaterThan($handoffBoundary->copy()->addHours(12))) {
            return false;
        }

        return $incomingShift->ends_at === null
            || $incomingShift->ends_at->greaterThan($incomingShift->starts_at);
    }

    protected function activeHandoverForShift(Shift $shift): ?ShiftHandover
    {
        return ShiftHandover::query()
            ->tap(fn (Builder $query) => $this->siteAccess->applyHandoverIntegrityScope($query))
            ->where('outgoing_shift_id', $shift->id)
            ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED])
            ->latest('id')
            ->first();
    }

    protected function effectiveSiteId(Shift $shift): ?int
    {
        return $shift->site_id ?: $shift->client?->site_id;
    }

    protected function positiveIdOrNull(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    protected function currentIncomingStaffId(ShiftHandover $handover): ?int
    {
        if (! $handover->incoming_shift_id) {
            return null;
        }

        return $handover->incomingShift?->user_id ? (int) $handover->incomingShift->user_id : null;
    }

    protected function assertAcknowledgementTargetStillValid(ShiftHandover $handover): void
    {
        if (! $handover->incoming_shift_id) {
            throw ValidationException::withMessages([
                'incoming_shift_id' => 'This handover is not bound to an exact incoming Shift and cannot be acknowledged.',
            ]);
        }

        $incomingShift = $handover->incomingShift;

        if (! $incomingShift || $incomingShift->status === 'cancelled') {
            throw ValidationException::withMessages([
                'handover' => 'This handover can no longer be acknowledged because the incoming shift is no longer active.',
            ]);
        }

        if (! $incomingShift->user_id) {
            throw ValidationException::withMessages([
                'handover' => 'This handover can no longer be acknowledged because the incoming shift no longer has an assigned staff member.',
            ]);
        }

    }

    /**
     * Resolve immutable controlled-drug count evidence. An omitted payload (the
     * controllers currently normalise that to an all-null array) and an exact
     * unchanged echo both preserve the stored evidence byte-for-byte. Any real
     * replacement is freshly governed and receives its own fail-closed audit.
     *
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $existing
     * @return array{verification: array<string, mixed>|null, audit_action: 'created'|'replaced'|null}
     */
    protected function resolveCdVerification(
        ?array $input,
        User $actor,
        Client $client,
        ?array $existing,
        Collection $lockedUsers,
        CarbonInterface $effectiveAt,
        Collection $lockedPresenceShifts,
    ): array {
        $input ??= [];
        $result = $input['result'] ?? null;
        $notes = isset($input['notes']) && is_scalar($input['notes'])
            ? trim((string) $input['notes'])
            : null;
        $notes = $notes === '' ? null : $notes;
        $witnessId = isset($input['witness_id']) && $input['witness_id'] !== ''
            ? (int) $input['witness_id']
            : null;
        $hasAnyInput = collect(['result', 'witness_id', 'witness_credential', 'notes'])
            ->contains(fn (string $key): bool => array_key_exists($key, $input)
                && $input[$key] !== null
                && $input[$key] !== '');

        if ($existing !== null && ! $hasAnyInput) {
            return ['verification' => $existing, 'audit_action' => null];
        }

        if ($hasAnyInput) {
            abort_unless(
                $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                    && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                404,
            );
        }

        if (! in_array($result, ['verified', 'discrepancy'], true)) {
            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'cd_result' => 'Recorded controlled-drug verification cannot be removed. Record a governed replacement instead.',
                ]);
            }
            if ($hasAnyInput) {
                throw ValidationException::withMessages([
                    'cd_result' => 'Choose the controlled-drug count result.',
                ]);
            }

            return ['verification' => null, 'audit_action' => null];
        }

        if (
            $existing !== null
            && ($existing['result'] ?? null) === $result
            && (int) ($existing['witness_id'] ?? 0) === (int) ($witnessId ?? 0)
            && (($existing['notes'] ?? null) === $notes)
        ) {
            return ['verification' => $existing, 'audit_action' => null];
        }

        if ($witnessId === null || $witnessId <= 0) {
            throw ValidationException::withMessages([
                'cd_witness_id' => 'Select the witnessing worker for this controlled-drug count.',
            ]);
        }
        if ($result === 'discrepancy' && $notes === null) {
            throw ValidationException::withMessages([
                'cd_notes' => 'Describe the controlled-drug count discrepancy before saving.',
            ]);
        }

        $verifiedAt = $effectiveAt;
        $ipAddress = (string) (request()->ip() ?: 'unknown');
        $rateLimitKey = $this->cdWitnessRateLimitKey(
            $actor,
            $witnessId,
            (int) $client->site_id,
            $ipAddress,
        );
        $rateLimited = false;

        try {
            $witnessAttestation = $this->medicationGovernance->confirmedControlledWitnessAttestation(
                $actor,
                $client,
                $witnessId,
                is_string($input['witness_credential'] ?? null) ? $input['witness_credential'] : null,
                witnessErrorKey: 'cd_witness_id',
                credentialErrorKey: 'cd_witness_credential',
                recorderId: (int) $actor->id,
                lockedUsers: $lockedUsers,
                effectiveAt: $verifiedAt,
                beforeCredentialCheck: function (User $qualifiedWitness) use (
                    $actor,
                    $client,
                    $ipAddress,
                    $rateLimitKey,
                    &$rateLimited,
                ): void {
                    if (! RateLimiter::tooManyAttempts($rateLimitKey, self::CD_WITNESS_ATTEMPT_LIMIT)) {
                        return;
                    }

                    $rateLimited = true;
                    $this->rejectCdWitnessCredential(
                        $actor,
                        $client,
                        (int) $qualifiedWitness->id,
                        $ipAddress,
                        'rate_limited',
                        RateLimiter::attempts($rateLimitKey),
                    );
                },
                lockedPresenceShifts: $lockedPresenceShifts,
            );
            $witness = $witnessAttestation['witness'];
        } catch (ValidationException $exception) {
            if ($rateLimited) {
                throw $exception;
            }
            if (! array_key_exists('cd_witness_credential', $exception->errors())) {
                throw $exception;
            }

            RateLimiter::hit($rateLimitKey, self::CD_WITNESS_DECAY_SECONDS);
            $this->rejectCdWitnessCredential(
                $actor,
                $client,
                $witnessId,
                $ipAddress,
                'rejected',
                RateLimiter::attempts($rateLimitKey),
            );
        }

        RateLimiter::clear($rateLimitKey);

        return [
            'verification' => [
                'result' => $result,
                'witness_id' => (int) $witness->id,
                'witness_name' => $witness->name,
                'notes' => $notes,
                'verified_at' => $verifiedAt->toISOString(),
                'verified_by' => $actor->id,
                'verified_by_name' => $actor->name,
                'witness_attestation' => $this->controlledWitnessAttestationSnapshot($witnessAttestation),
            ],
            'audit_action' => $existing === null ? 'created' : 'replaced',
        ];
    }

    /**
     * @param array{
     *   witnessed_at: CarbonInterface,
     *   authority_permission: string,
     *   employment_profile_id: int,
     *   competency_state: string,
     *   competency_assessment_id: int,
     *   presence_source: string,
     *   presence_record_id: int,
     *   presence_started_at: string,
     *   presence_ends_at: ?string
     * } $attestation
     * @return array<string, int|string|null>
     */
    protected function controlledWitnessAttestationSnapshot(array $attestation): array
    {
        return [
            'witnessed_at' => $attestation['witnessed_at']->toIso8601String(),
            'authority_permission' => $attestation['authority_permission'],
            'employment_profile_id' => $attestation['employment_profile_id'],
            'competency_state' => $attestation['competency_state'],
            'competency_assessment_id' => $attestation['competency_assessment_id'],
            'presence_source' => $attestation['presence_source'],
            'presence_record_id' => $attestation['presence_record_id'],
            'presence_started_at' => $attestation['presence_started_at'],
            'presence_ends_at' => $attestation['presence_ends_at'],
        ];
    }

    protected function cdWitnessRateLimitKey(
        User $actor,
        int $witnessId,
        int $siteId,
        string $ipAddress,
    ): string {
        return implode(':', [
            'shift-handover',
            'cd-witness',
            (int) $actor->id,
            $witnessId,
            $siteId,
            hash('sha256', $ipAddress),
        ]);
    }

    protected function rejectCdWitnessCredential(
        User $actor,
        Client $client,
        int $witnessId,
        string $ipAddress,
        string $outcome,
        int $attempts,
    ): never {
        Log::warning('Shift handover controlled-drug witness credential rejected.', [
            'security_event' => 'shift_handover_cd_witness_credential_rejected',
            'outcome' => $outcome,
            'actor_id' => (int) $actor->id,
            'witness_id' => $witnessId,
            'client_id' => (int) $client->id,
            'site_id' => (int) $client->site_id,
            'ip_address' => $ipAddress,
            'attempts' => $attempts,
            'attempt_limit' => self::CD_WITNESS_ATTEMPT_LIMIT,
        ]);

        throw ValidationException::withMessages([
            'cd_witness_credential' => self::CD_WITNESS_FAILURE,
        ]);
    }

    /**
     * Build a snapshot of clinical observations recorded during a shift.
     *
     * @return array<int, array{type: string, type_label: string, summary: string, recorded_at: string, recorder: string|null}>|null
     */
    protected function buildObservationsSummary(Shift $shift): ?array
    {
        $observations = ClinicalObservation::query()
            ->forShift($shift->id)
            ->with('recorder:id,name')
            ->orderBy('recorded_at')
            ->get();

        if ($observations->isEmpty()) {
            return null;
        }

        return $observations->map(function (ClinicalObservation $obs) {
            return [
                'type' => $obs->observation_type->value,
                'type_label' => $obs->observation_type->label(),
                'summary' => $this->summariseObservation($obs),
                'recorded_at' => $obs->recorded_at->toISOString(),
                'recorder' => $obs->recorder?->name,
            ];
        })->values()->all();
    }

    protected function summariseObservation(ClinicalObservation $obs): string
    {
        $d = $obs->data;

        return match ($obs->observation_type->value) {
            'vitals' => implode(', ', array_filter([
                isset($d['systolic'], $d['diastolic']) ? "BP {$d['systolic']}/{$d['diastolic']}" : null,
                isset($d['pulse']) ? "P{$d['pulse']}" : null,
                isset($d['temperature']) ? "{$d['temperature']}\u{00B0}C" : null,
            ])),
            'weight' => isset($d['weight_kg']) ? "{$d['weight_kg']} kg" : '',
            'bowel' => isset($d['bristol_type']) ? "Bristol type {$d['bristol_type']}" : '',
            'sleep' => isset($d['quality']) ? ucfirst($d['quality']).' sleep' : '',
            'fluid_intake' => isset($d['amount_ml']) ? "{$d['amount_ml']}ml" : '',
            'pain' => isset($d['score']) ? "Pain {$d['score']}/10" : '',
            default => $obs->notes ?? '',
        };
    }

    /**
     * A request without an expected version is a create intent. Once another
     * worker has won the aggregate lock, only an exact retry of that same
     * material intent may converge on the durable row; it must never become an
     * implicit update.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function firstSaveRetryMatches(
        ShiftHandover $existing,
        array $attributes,
        bool $submit,
    ): bool {
        if ($existing->status !== ($submit ? self::STATUS_SUBMITTED : self::STATUS_DRAFT)) {
            return false;
        }

        foreach ([
            'outgoing_shift_id',
            'incoming_shift_id',
            'client_id',
            'outgoing_staff_id',
            'incoming_staff_id',
            'handover_notes',
            'client_mood',
            'tasks_pending',
            'medications_due',
            'incidents_to_note',
            'follow_up_items',
            'cd_verification',
        ] as $key) {
            $stored = $existing->getAttribute($key);
            $requested = $attributes[$key] ?? null;
            if (is_array($stored) || is_array($requested)) {
                if (json_encode($stored) !== json_encode($requested)) {
                    return false;
                }

                continue;
            }

            if ((string) ($stored ?? '') !== (string) ($requested ?? '')) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeStructuredItems(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : null;
        }

        if (is_array($value)) {
            return array_values($value);
        }

        if ($value instanceof Collection) {
            return $value->values()->all();
        }

        return null;
    }
}
