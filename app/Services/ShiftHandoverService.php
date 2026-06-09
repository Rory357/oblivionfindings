<?php

namespace App\Services;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftHandoverService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    /**
     * Days the outgoing/assigned staff member may keep editing a posted handover
     * after the shift. Past this window it locks to managers only. Drafts stay
     * editable by their author regardless of age.
     */
    public const EDIT_WINDOW_DAYS = 7;

    public function __construct(
        protected ShiftTimelineService $timelineService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{handover: ShiftHandover, action: string}
     */
    public function save(Shift $outgoingShift, User $actor, array $data): array
    {
        $outgoingShift->loadMissing([
            'tasks:id,shift_id,label,is_completed',
            'incidents:id,shift_id,type,severity,status,occurred_at',
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'serviceContext:id,name,type',
            'staff:id,name',
        ]);

        $submit = (bool) ($data['submit'] ?? true);
        $existing = $this->activeHandoverForShift($outgoingShift);

        if ($existing?->status === self::STATUS_ACKNOWLEDGED) {
            throw ValidationException::withMessages([
                'handover' => 'This shift handover has already been acknowledged and cannot be replaced.',
            ]);
        }

        if ($existing && $existing->status === self::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'handover' => 'A submitted handover already exists for this shift.',
            ]);
        }

        return DB::transaction(function () use ($outgoingShift, $actor, $data, $submit, $existing) {
            $incomingShift = $this->resolveIncomingShift($outgoingShift, $data['incoming_shift_id'] ?? null);

            $handover = $existing && $existing->status === self::STATUS_DRAFT
                ? $existing
                : new ShiftHandover();

            $handover->fill([
                'organization_id' => $actor->organization_id,
                'outgoing_shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $incomingShift?->id,
                'client_id' => $data['client_id'] ?? $outgoingShift->client_id,
                'outgoing_staff_id' => $outgoingShift->user_id ?: $actor->id,
                'incoming_staff_id' => $incomingShift?->user_id ?? ($data['incoming_staff_id'] ?? null),
                'handover_notes' => (string) $data['handover_notes'],
                'client_mood' => $data['client_mood'] ?? null,
                'tasks_pending' => $this->normalizeStructuredItems($data['tasks_pending'] ?? null)
                    ?? $outgoingShift->tasks
                        ->where('is_completed', false)
                        ->map(fn ($task) => ['id' => $task->id, 'label' => $task->label])
                        ->values()
                        ->all(),
                'medications_due' => $this->normalizeStructuredItems($data['medications_due'] ?? null),
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
                'status' => self::STATUS_DRAFT,
            ]);

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

            $this->timelineService->recordHandoverCreated($freshHandover, $outgoingShift, $actor);
            AuditLogger::log('shift.handover.created', $freshHandover, [
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
        if ($handover->status === self::STATUS_ACKNOWLEDGED) {
            throw ValidationException::withMessages([
                'handover' => 'Acknowledged handovers cannot be resubmitted.',
            ]);
        }

        return DB::transaction(function () use ($handover, $actor) {
            $handover = ShiftHandover::query()
                ->lockForUpdate()
                ->findOrFail($handover->id);

            if ($handover->status === self::STATUS_SUBMITTED) {
                return $handover->fresh([
                    'outgoingShift.client:id,first_name,last_name,site_id',
                    'outgoingShift.site:id,name,type',
                    'outgoingShift.serviceContext:id,name,type',
                    'outgoingShift.staff:id,name',
                    'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                    'incomingStaff:id,name',
                    'outgoingStaff:id,name',
                ]) ?? $handover;
            }

            $handover->loadMissing([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
            ]);

            // resolveExpectedIncomingShift returns a plain array (see its
            // docblock above), so use array access — the previous `->get()`
            // call threw "Call to a member function get() on array" whenever a
            // worker submitted a handover for a shift without an existing
            // incoming match.
            $matchedIncoming = $handover->incomingShift
                ?: $this->resolveExpectedIncomingShift($handover->outgoingShift)['matched_shift'];

            $handover->forceFill([
                'incoming_shift_id' => $handover->incoming_shift_id ?: $matchedIncoming?->id,
                'incoming_staff_id' => $matchedIncoming?->user_id ?: $handover->incoming_staff_id,
                'status' => self::STATUS_SUBMITTED,
                'submitted_at' => $handover->submitted_at ?? now(),
                'submitted_by' => $actor->id,
            ])->save();

            $fresh = $handover->fresh([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
            ]) ?? $handover;

            $this->timelineService->recordHandoverSubmitted($fresh, $fresh->outgoingShift, $actor);
            AuditLogger::log('shift.handover.submitted', $fresh, [
                'shift_id' => $fresh->outgoing_shift_id,
                'incoming_shift_id' => $fresh->incoming_shift_id,
            ]);

            return $fresh;
        });
    }

    public function acknowledge(ShiftHandover $handover, User $actor): ShiftHandover
    {
        if ($handover->status === self::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'handover' => 'Draft handovers must be submitted before they can be acknowledged.',
            ]);
        }

        if ($handover->status === self::STATUS_ACKNOWLEDGED) {
            return $handover->fresh() ?? $handover;
        }

        return DB::transaction(function () use ($handover, $actor) {
            $handover = ShiftHandover::query()
                ->lockForUpdate()
                ->findOrFail($handover->id);

            $handover->loadMissing([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
            ]);
            $this->assertAcknowledgementTargetStillValid($handover);

            $handover->forceFill([
                'incoming_staff_id' => $this->currentIncomingStaffId($handover) ?? $handover->incoming_staff_id,
                'status' => self::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => now(),
                'acknowledged_by' => $actor->id,
            ])->save();

            $fresh = $handover->fresh([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
                'acknowledger:id,name',
            ]) ?? $handover;

            $this->timelineService->recordHandoverAcknowledged($fresh, $fresh->outgoingShift, $actor);
            AuditLogger::log('shift.handover.acknowledged', $fresh, [
                'shift_id' => $fresh->outgoing_shift_id,
                'incoming_shift_id' => $fresh->incoming_shift_id,
            ]);

            return $fresh;
        });
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
    public function completionRequirement(Shift $shift): array
    {
        $expectation = $this->resolveExpectedIncomingShift($shift);
        $matchedShift = $expectation['matched_shift'];

        $matchedHandover = null;
        if ($matchedShift) {
            $matchedHandover = ShiftHandover::query()
                ->where('outgoing_shift_id', $shift->id)
                ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED])
                ->where(function (Builder $query) use ($matchedShift) {
                    $query->where('incoming_shift_id', $matchedShift->id)
                        ->orWhereNull('incoming_shift_id');
                })
                ->latest('submitted_at')
                ->latest('id')
                ->first();
        }

        return [
            'requires_handover' => $matchedShift !== null,
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

    public function recordCompletionWaiver(Shift $shift, User $actor, string $reason, array $requirement): void
    {
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

        AuditLogger::log('shift.handover.waived', $shift, [
            'shift_id' => $shift->id,
            'reason' => $reason,
            'matched_incoming_shift_id' => $requirement['matched_shift']?->id,
            'ambiguous_match' => (bool) ($requirement['ambiguous'] ?? false),
        ]);
    }

    /**
     * @return array{
     *     matched_shift: Shift|null,
     *     ambiguous: bool,
     *     candidate_ids: array<int, int>
     * }
     */
    public function resolveExpectedIncomingShift(Shift $outgoingShift): array
    {
        $outgoingShift->loadMissing([
            'client:id,first_name,last_name,site_id',
            'site:id,name,type',
            'serviceContext:id,name,type',
        ]);

        $windowStart = $outgoingShift->starts_at ?? now()->subHour();
        $windowEnd = ($outgoingShift->actual_ends_at ?? $outgoingShift->ends_at ?? $windowStart)->copy()->addHours(12);
        $siteId = $this->effectiveSiteId($outgoingShift);

        $query = Shift::query()
            ->with(['client:id,first_name,last_name,site_id', 'site:id,name,type', 'serviceContext:id,name,type', 'staff:id,name'])
            ->whereKeyNot($outgoingShift->id)
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($outgoingShift->client_id) {
            $query->where('client_id', $outgoingShift->client_id);
        } elseif ($siteId) {
            $query->where(function (Builder $builder) use ($siteId) {
                $builder->where('site_id', $siteId)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->where('site_id', $siteId));
            });
        } else {
            return [
                'matched_shift' => null,
                'ambiguous' => false,
                'candidate_ids' => [],
            ];
        }

        if ($outgoingShift->service_context_id) {
            $query->where('service_context_id', $outgoingShift->service_context_id);
        }

        $candidates = $query->take(3)->get()->values();

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

    public function canSubmit(ShiftHandover $handover, ?User $auth): bool
    {
        if (! $auth) {
            return false;
        }

        if ($this->canViewAny($auth)) {
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

        if ($this->canViewAny($auth)) {
            return true;
        }

        if ($handover->status !== self::STATUS_SUBMITTED) {
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

        if ($this->canViewAny($auth) || $auth->canDo('shifts.manageAny')) {
            return ['editable' => true, 'locked' => false, 'reason' => 'manager', 'days_left' => null, 'age_days' => null];
        }

        $isOwner = (int) $handover->outgoing_staff_id === (int) $auth->id
            || (int) ($handover->outgoingShift?->user_id ?? 0) === (int) $auth->id;

        if (! $isOwner) {
            return ['editable' => false, 'locked' => true, 'reason' => 'not_owner', 'days_left' => null, 'age_days' => null];
        }

        if ($handover->status === self::STATUS_DRAFT) {
            return ['editable' => true, 'locked' => false, 'reason' => 'draft', 'days_left' => null, 'age_days' => null];
        }

        $reference = $handover->outgoingShift?->starts_at ?? $handover->created_at;
        $ageDays = $reference
            ? (int) $reference->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : 0;

        if ($ageDays >= self::EDIT_WINDOW_DAYS) {
            return ['editable' => false, 'locked' => true, 'reason' => 'window_closed', 'days_left' => 0, 'age_days' => $ageDays];
        }

        return [
            'editable' => true,
            'locked' => false,
            'reason' => 'within_window',
            'days_left' => self::EDIT_WINDOW_DAYS - $ageDays,
            'age_days' => $ageDays,
        ];
    }

    /**
     * Apply a direct content edit to an existing handover (narrative, mood, and
     * the four structured lists). Unlike save(), this works on submitted and
     * acknowledged handovers (gated by editPermission) without re-running the
     * shift-keyed upsert. Incoming shift/staff may only be re-targeted while the
     * handover is still a draft, to respect the acknowledgement invariants.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyEdit(ShiftHandover $handover, User $actor, array $data): ShiftHandover
    {
        return DB::transaction(function () use ($handover, $actor, $data) {
            $handover = ShiftHandover::query()->lockForUpdate()->findOrFail($handover->id);
            $handover->loadMissing(['outgoingShift:id,user_id,client_id,site_id,service_context_id,starts_at,ends_at,status']);

            $attributes = [
                'handover_notes' => (string) $data['handover_notes'],
                'client_mood' => $data['client_mood'] ?? null,
            ];

            foreach (['medications_due', 'incidents_to_note', 'follow_up_items', 'tasks_pending'] as $listKey) {
                if (array_key_exists($listKey, $data)) {
                    $attributes[$listKey] = $this->normalizeStructuredItems($data[$listKey]);
                }
            }

            // Re-targeting the incoming shift/worker is only safe on a draft —
            // once submitted/acknowledged the acknowledgement invariants pin it.
            if ($handover->status === self::STATUS_DRAFT && array_key_exists('incoming_shift_id', $data)) {
                $incomingShift = $this->resolveIncomingShift($handover->outgoingShift, $data['incoming_shift_id'] ?? null);
                $attributes['incoming_shift_id'] = $incomingShift?->id;
                $attributes['incoming_staff_id'] = $incomingShift?->user_id ?? ($data['incoming_staff_id'] ?? null);
            }

            $handover->fill($attributes)->save();

            $fresh = $handover->fresh([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingStaff:id,name',
                'outgoingStaff:id,name',
            ]) ?? $handover;

            AuditLogger::log('shift.handover.updated', $fresh, [
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

    protected function resolveIncomingShift(Shift $outgoingShift, mixed $incomingShiftId): ?Shift
    {
        if ($incomingShiftId) {
            $incomingShift = Shift::query()
                ->with(['client:id,first_name,last_name,site_id', 'site:id,name,type', 'serviceContext:id,name,type', 'staff:id,name'])
                ->findOrFail((int) $incomingShiftId);

            if (! $this->incomingShiftMatches($outgoingShift, $incomingShift)) {
                throw ValidationException::withMessages([
                    'incoming_shift_id' => 'The selected incoming shift does not match the outgoing handover context.',
                ]);
            }

            return $incomingShift;
        }

        return $this->resolveExpectedIncomingShift($outgoingShift)['matched_shift'];
    }

    protected function incomingShiftMatches(Shift $outgoingShift, Shift $incomingShift): bool
    {
        if ((int) $incomingShift->id === (int) $outgoingShift->id) {
            return false;
        }

        if ($incomingShift->status === 'cancelled') {
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

        return ! $incomingShift->starts_at || ! $outgoingShift->starts_at
            ? true
            : $incomingShift->starts_at->greaterThanOrEqualTo($outgoingShift->starts_at);
    }

    protected function activeHandoverForShift(Shift $shift): ?ShiftHandover
    {
        return ShiftHandover::query()
            ->where('outgoing_shift_id', $shift->id)
            ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED])
            ->latest('id')
            ->first();
    }

    protected function effectiveSiteId(Shift $shift): ?int
    {
        return $shift->site_id ?: $shift->client?->site_id;
    }

    protected function currentIncomingStaffId(ShiftHandover $handover): ?int
    {
        if ($handover->incoming_shift_id) {
            return $handover->incomingShift?->user_id ? (int) $handover->incomingShift->user_id : null;
        }

        return $handover->incoming_staff_id ? (int) $handover->incoming_staff_id : null;
    }

    protected function assertAcknowledgementTargetStillValid(ShiftHandover $handover): void
    {
        if (! $handover->incoming_shift_id) {
            return;
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
            'sleep' => isset($d['quality']) ? ucfirst($d['quality']) . ' sleep' : '',
            'fluid_intake' => isset($d['amount_ml']) ? "{$d['amount_ml']}ml" : '',
            'pain' => isset($d['score']) ? "Pain {$d['score']}/10" : '',
            default => $obs->notes ?? '',
        };
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
