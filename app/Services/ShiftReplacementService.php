<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftReplacementService
{
    public const REQUESTED = 'requested';

    public const CLAIMED = 'claimed';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ShiftStaffEligibilityService $eligibility,
    ) {}

    public function request(Shift $shift, User $actor, array $data): ShiftReplacementRequest
    {
        $this->siteAccess->assertCanAccessShift($actor, $shift, ['reports.viewAny']);
        $shift->loadMissing(['client:id,first_name,last_name,site_id', 'staff:id,name']);

        if (! $actor->canDo('shifts.manageAny') && ! (
            $actor->canDo('shifts.update')
            && (int) $shift->user_id === (int) $actor->id
        )) {
            throw ValidationException::withMessages([
                'replacement' => 'You are not authorized to request replacement cover for this shift.',
            ]);
        }

        if (! $shift->user_id) {
            throw ValidationException::withMessages([
                'shift' => 'Only assigned shifts can have a replacement requested.',
            ]);
        }

        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'shift' => 'Replacement cannot be requested for completed or cancelled shifts.',
            ]);
        }

        if ($this->activeForShift($shift)) {
            throw ValidationException::withMessages([
                'replacement' => 'This shift already has an active replacement request.',
            ]);
        }

        return DB::transaction(function () use ($shift, $actor, $data) {
            $lockedShift = Shift::query()
                ->tap(fn ($query) => $this->siteAccess->applyShiftScope($query, $actor, ['reports.viewAny']))
                ->lockForUpdate()
                ->findOrFail($shift->id);
            $lockedShift->loadMissing(['client:id,first_name,last_name,site_id', 'staff:id,name']);

            if ($this->activeForShift($lockedShift)) {
                throw ValidationException::withMessages([
                    'replacement' => 'This shift already has an active replacement request.',
                ]);
            }

            $replacement = ShiftReplacementRequest::create([
                'shift_id' => $lockedShift->id,
                'requested_by' => $actor->id,
                'current_staff_id' => $lockedShift->user_id,
                'status' => self::REQUESTED,
                'reason' => (string) $data['reason'],
                'notes' => $data['notes'] ?? null,
                'required_skills' => array_values($data['required_skills'] ?? []),
                'requested_at' => now(),
            ]);

            if (! empty($data['publish_to_job_board'])) {
                $existingPosition = ShiftOpenPosition::query()
                    ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionIntegrityScope($query))
                    ->where('shift_id', $lockedShift->id)
                    ->whereIn('status', ['open', 'claimed'])
                    ->first();

                if ($existingPosition) {
                    throw ValidationException::withMessages([
                        'publish_to_job_board' => 'This shift already has an active open position on the job board.',
                    ]);
                }

                $position = ShiftOpenPosition::create([
                    'shift_id' => $lockedShift->id,
                    'replacement_request_id' => $replacement->id,
                    'status' => 'open',
                    'required_skills' => array_values($data['required_skills'] ?? []),
                    'coverage_roles' => $lockedShift->coverage_roles ?? [],
                    'notes' => $this->buildOpenPositionNotes($lockedShift, (string) $data['reason'], $data['notes'] ?? null),
                    'expires_at' => $data['expires_at'] ?? null,
                ]);

                $replacement->setRelation('openPosition', $position);
            }

            $this->logTimelineEvent(
                $replacement->fresh(['requester', 'currentStaff', 'shift.client']),
                'shift_replacement_requested',
                $actor,
                'Replacement requested',
                $this->buildRequestedBody($lockedShift, (string) $data['reason'])
            );

            $client = $lockedShift->client;
            app(NotificationService::class)->notifyCrud($actor, 'requested', 'shift replacement', $replacement, $client, [
                'title' => 'Shift replacement requested',
                'body' => $this->buildRequestedBody($lockedShift, (string) $data['reason']),
                'url' => url("/operations/shifts/{$lockedShift->id}"),
                'include_managers' => false,
                'include_assigned_workers' => false,
                'include_entity_user' => false,
                'target_user_ids' => array_values(array_unique(array_filter([
                    $lockedShift->user_id,
                    ...$this->managerRecipientIdsForShift($lockedShift),
                ]))),
            ]);

            return $replacement->fresh([
                'requester:id,name',
                'currentStaff:id,name',
                'replacementStaff:id,name',
                'openPosition',
            ]);
        });
    }

    public function cancel(ShiftReplacementRequest $replacement, User $actor): ShiftReplacementRequest
    {
        $this->siteAccess->assertCanAccessShiftReplacementRequest($actor, $replacement, ['reports.viewAny']);
        $replacement->loadMissing(['shift.client:id,first_name,last_name,site_id', 'openPosition']);

        if (! $actor->canDo('shifts.manageAny') && ! (
            $actor->canDo('shifts.update')
            && in_array((int) $actor->id, [
                (int) $replacement->requested_by,
                (int) $replacement->current_staff_id,
            ], true)
        )) {
            throw ValidationException::withMessages([
                'replacement' => 'You are not authorized to cancel this replacement request.',
            ]);
        }

        if (! in_array($replacement->status, [self::REQUESTED, self::CLAIMED], true)) {
            throw ValidationException::withMessages([
                'replacement' => 'Only active replacement requests can be cancelled.',
            ]);
        }

        return DB::transaction(function () use ($replacement, $actor) {
            $replacement->update([
                'status' => self::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            if ($replacement->openPosition && in_array($replacement->openPosition->status, ['open', 'claimed'], true)) {
                $replacement->openPosition->update([
                    'status' => 'cancelled',
                ]);
            }

            $this->logTimelineEvent(
                $replacement->fresh(['shift.client', 'requester', 'currentStaff', 'replacementStaff']),
                'shift_replacement_cancelled',
                $actor,
                'Replacement request cancelled',
                'The replacement workflow for this shift was cancelled.'
            );

            $freshReplacement = $replacement->fresh(['shift.client', 'requester', 'currentStaff', 'replacementStaff']);
            $client = $freshReplacement->shift?->client;
            app(NotificationService::class)->notifyCrud($actor, 'cancelled', 'shift replacement', $freshReplacement, $client, [
                'title' => 'Shift replacement cancelled',
                'body' => 'The replacement workflow for this shift was cancelled.',
                'url' => url("/operations/shifts/{$freshReplacement->shift_id}"),
                'include_managers' => false,
                'include_assigned_workers' => false,
                'include_entity_user' => false,
                'target_user_ids' => array_values(array_filter([
                    $freshReplacement->requested_by,
                    $freshReplacement->current_staff_id,
                    $freshReplacement->replacement_user_id,
                ])),
            ]);

            return $replacement->fresh([
                'requester:id,name',
                'currentStaff:id,name',
                'replacementStaff:id,name',
                'openPosition',
            ]);
        });
    }

    public function cancelActiveForShift(Shift $shift, User $actor): ?ShiftReplacementRequest
    {
        $replacement = $this->activeForShift($shift);
        if (! $replacement) {
            return null;
        }

        return $this->cancel($replacement, $actor);
    }

    public function syncClaimFromOpenPosition(ShiftOpenPosition $position): void
    {
        $position = ShiftOpenPosition::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionIntegrityScope($query))
            ->with(['replacementRequest', 'claimer', 'shift.client'])
            ->find($position->id);
        if (! $position || ! $position->claimer || ! $position->shift) {
            return;
        }

        $this->assertStaffEligibleForShiftSite($position->shift, $position->claimer);

        $replacement = $position->replacementRequest;
        if (! $replacement || ! in_array($replacement->status, [self::REQUESTED, self::CLAIMED], true)) {
            return;
        }

        $eligibility = $this->eligibility->evaluate($position->shift, $position->claimer);
        if ($eligibility->hasBlocks()) {
            throw ValidationException::withMessages([
                'claim' => $eligibility->blocking_reasons[0] ?? 'The claimant is no longer eligible for this Shift.',
            ]);
        }

        if (
            $replacement->status === self::CLAIMED
            && (int) $replacement->replacement_user_id === (int) $position->claimed_by
        ) {
            return;
        }

        $replacement->loadMissing(['shift.client:id,first_name,last_name,site_id', 'requester:id,name']);

        $replacement->update([
            'status' => self::CLAIMED,
            'replacement_user_id' => $position->claimed_by,
            'claimed_at' => $position->claimed_at ?? now(),
        ]);

        $claimer = $position->claimer;
        $this->logTimelineEvent(
            $replacement->fresh(['shift.client', 'requester', 'currentStaff', 'replacementStaff']),
            'shift_replacement_claimed',
            $claimer,
            'Replacement claim submitted',
            $claimer?->name
                ? $claimer->name.' has claimed this replacement request and is awaiting approval.'
                : 'A replacement claim was submitted and is awaiting approval.'
        );

        $freshReplacement = $replacement->fresh(['shift.client', 'requester', 'currentStaff', 'replacementStaff']);
        $client = $freshReplacement->shift?->client;
        app(NotificationService::class)->notifyCrud($claimer, 'claimed', 'shift replacement', $freshReplacement, $client, [
            'title' => 'Shift replacement claim submitted',
            'body' => $claimer?->name
                ? $claimer->name.' has claimed this replacement request and is awaiting approval.'
                : 'A replacement claim was submitted and is awaiting approval.',
            'url' => url("/operations/shifts/{$freshReplacement->shift_id}"),
            'include_managers' => false,
            'include_assigned_workers' => false,
            'include_entity_user' => false,
            'target_user_ids' => array_values(array_unique(array_filter([
                $freshReplacement->requested_by,
                $freshReplacement->current_staff_id,
                ...$this->managerRecipientIdsForShift($freshReplacement->shift),
            ]))),
        ]);
    }

    public function approveFromOpenPosition(ShiftOpenPosition $position, User $actor): void
    {
        $this->siteAccess->assertCanAccessShiftOpenPosition($actor, $position, ['reports.viewAny']);
        if (! ($actor->canDo('job_board.approve') || $actor->canDo('shifts.manageAny'))) {
            throw ValidationException::withMessages([
                'replacement' => 'You are not authorized to approve replacement claims.',
            ]);
        }

        $replacement = $position->replacementRequest;
        if (! $replacement || ! $position->claimed_by) {
            return;
        }

        $replacement->loadMissing(['shift.client:id,first_name,last_name,site_id', 'currentStaff:id,name']);
        $this->assertReplacementStillActionable($replacement->shift);

        $replacementUser = User::query()->find($position->claimed_by);
        if (! $replacementUser) {
            throw ValidationException::withMessages([
                'replacement' => 'The claimed staff member is no longer available.',
            ]);
        }

        $this->assertStaffEligibleForShiftSite($replacement->shift, $replacementUser);
        $eligibility = $this->eligibility->evaluate($replacement->shift, $replacementUser);
        if ($eligibility->hasBlocks()) {
            throw ValidationException::withMessages([
                'replacement' => $eligibility->blocking_reasons[0] ?? 'The claimed staff member is no longer eligible for this Shift.',
            ]);
        }

        if (
            $replacement->status === self::APPROVED
            && (int) $replacement->replacement_user_id === (int) $position->claimed_by
        ) {
            return;
        }

        $replacement->update([
            'status' => self::APPROVED,
            'replacement_user_id' => $position->claimed_by,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        $this->cancelOtherPositionsForShift($position->shift_id, $position->id);

        $this->logTimelineEvent(
            $replacement->fresh(['shift.client', 'requester', 'currentStaff', 'replacementStaff']),
            'shift_replacement_approved',
            $actor,
            'Replacement approved',
            $replacementUser?->name
                ? $replacementUser->name.' was approved as the replacement staff member.'
                : 'The replacement request was approved.'
        );

        $client = $replacement->shift?->client;
        app(NotificationService::class)->notifyCrud($actor, 'approved', 'shift replacement', $replacement, $client, [
            'title' => 'Shift replacement approved',
            'body' => $replacementUser?->name
                ? $replacementUser->name.' has been assigned to the shift.'
                : 'A replacement has been approved for the shift.',
            'url' => url("/operations/shifts/{$replacement->shift_id}"),
            'include_managers' => false,
            'include_assigned_workers' => false,
            'include_entity_user' => false,
            'target_user_ids' => array_values(array_unique(array_filter([
                $replacement->requested_by,
                $replacement->current_staff_id,
                $replacement->replacement_user_id,
                ...$this->managerRecipientIdsForShift($replacement->shift),
            ]))),
        ]);
    }

    public function resolveFromManualAssignment(Shift $shift, int $assignedUserId, User $actor): void
    {
        $this->siteAccess->assertCanAccessShift($actor, $shift, ['reports.viewAny']);
        $replacement = $this->activeForShift($shift);
        if (! $replacement) {
            return;
        }

        $replacement->loadMissing(['shift.client:id,first_name,last_name,site_id', 'requester:id,name', 'openPosition']);
        $this->assertReplacementStillActionable($shift);

        $replacementUser = User::query()->find($assignedUserId);
        if (! $replacementUser) {
            throw ValidationException::withMessages([
                'replacement' => 'The assigned staff member is no longer available.',
            ]);
        }

        $this->assertStaffEligibleForShiftSite($shift, $replacementUser);
        $eligibility = $this->eligibility->evaluate($shift, $replacementUser);
        if ($eligibility->hasBlocks()) {
            throw ValidationException::withMessages([
                'replacement' => $eligibility->blocking_reasons[0] ?? 'The assigned staff member is not eligible for this Shift.',
            ]);
        }

        if (
            $replacement->status === self::APPROVED
            && (int) $replacement->replacement_user_id === $assignedUserId
        ) {
            return;
        }

        $replacement->update([
            'status' => self::APPROVED,
            'replacement_user_id' => $assignedUserId,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        if ($replacement->openPosition && in_array($replacement->openPosition->status, ['open', 'claimed'], true)) {
            $replacement->openPosition->update([
                'claimed_by' => $assignedUserId,
                'claimed_at' => $replacement->openPosition->claimed_at ?? now(),
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'status' => 'filled',
            ]);
        }

        $this->cancelOtherPositionsForShift($shift->id, $replacement->openPosition?->id);

        $this->logTimelineEvent(
            $replacement->fresh(['shift.client', 'requester', 'currentStaff', 'replacementStaff']),
            'shift_replacement_approved',
            $actor,
            'Replacement approved',
            $replacementUser?->name
                ? $replacementUser->name.' was assigned to the shift manually.'
                : 'The replacement request was resolved by manual assignment.'
        );
    }

    public function activeForShift(Shift $shift): ?ShiftReplacementRequest
    {
        return ShiftReplacementRequest::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftReplacementIntegrityScope($query))
            ->where('shift_id', $shift->id)
            ->active()
            ->latest('requested_at')
            ->first();
    }

    protected function cancelOtherPositionsForShift(int $shiftId, ?int $exceptPositionId = null): void
    {
        ShiftOpenPosition::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionIntegrityScope($query))
            ->where('shift_id', $shiftId)
            ->when($exceptPositionId, fn ($query) => $query->where('id', '!=', $exceptPositionId))
            ->whereIn('status', ['open', 'claimed'])
            ->update(['status' => 'cancelled']);
    }

    protected function assertReplacementStillActionable(?Shift $shift): void
    {
        if (! $shift || in_array($shift->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'shift' => 'This replacement request can no longer be approved because the original shift is no longer active.',
            ]);
        }
    }

    protected function assertStaffEligibleForShiftSite(Shift $shift, User $staff): void
    {
        $siteId = $this->siteAccess->shiftSiteId($shift);
        $eligibleStaff = User::query()->whereKey($staff->id);

        if (! $siteId || ! $this->siteAccess->applyFleetRecipientEligibility($eligibleStaff, $siteId)->exists()) {
            throw ValidationException::withMessages([
                'replacement' => 'The selected staff member is not currently eligible to work at this Shift Site.',
            ]);
        }
    }

    /** @return array<int, int> */
    protected function managerRecipientIdsForShift(?Shift $shift): array
    {
        if (! $shift || ! ($siteId = $this->siteAccess->shiftSiteId($shift))) {
            return [];
        }

        $managers = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', NotificationService::MANAGER_ROLES));

        return $this->siteAccess
            ->applyFleetRecipientEligibility($managers, $siteId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function buildRequestedBody(Shift $shift, string $reason): string
    {
        $staff = $shift->staff?->name ? 'Current staff: '.$shift->staff->name : null;

        return collect([
            'Reason: '.$reason,
            $staff,
            $shift->starts_at && $shift->ends_at
                ? 'Shift: '.$shift->starts_at->format('D j M, g:i A').' - '.$shift->ends_at->format('g:i A')
                : null,
        ])->filter()->implode(' · ');
    }

    protected function buildOpenPositionNotes(Shift $shift, string $reason, ?string $notes): string
    {
        return collect([
            'Replacement needed',
            'Reason: '.$reason,
            $notes ? 'Notes: '.$notes : null,
            $shift->staff?->name ? 'Current staff: '.$shift->staff->name : null,
        ])->filter()->implode("\n");
    }

    protected function logTimelineEvent(
        ShiftReplacementRequest $replacement,
        string $type,
        ?User $actor,
        string $subject,
        ?string $body = null
    ): void {
        $replacement->loadMissing([
            'shift.client:id,first_name,last_name,site_id',
            'shift.staff:id,name',
            'shift.serviceContext:id,name,type',
            'requester:id,name',
            'currentStaff:id,name',
            'replacementStaff:id,name',
        ]);

        $shift = $replacement->shift;
        $client = $shift?->client;

        app(TimelineEmitter::class)->record([
            'source_type' => ShiftReplacementRequest::class,
            'source_id' => $replacement->id,
            'occurred_at' => now(),
            'type' => $type,
            'actor_user_id' => $actor?->id,
            'client_id' => $shift?->client_id,
            'shift_id' => $shift?->id,
            'site_id' => $client?->site_id,
            'subject' => $subject,
            'body' => $body,
            'meta' => array_filter([
                'replacement_request_id' => $replacement->id,
                'status' => $replacement->status,
                'reason' => $replacement->reason,
                'requested_by' => $replacement->requester?->name,
                'current_staff' => $replacement->currentStaff?->name,
                'replacement_staff' => $replacement->replacementStaff?->name,
                'shift_type' => $shift?->shift_type ?? 'standard',
                'starts_at' => $shift?->starts_at?->toISOString(),
                'ends_at' => $shift?->ends_at?->toISOString(),
                'location' => $shift?->location,
                'service_context' => $shift?->serviceContext?->name,
                'staff_name' => $shift?->staff?->name,
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => 'internal',
            'created_by' => $actor?->id,
        ]);
    }
}
