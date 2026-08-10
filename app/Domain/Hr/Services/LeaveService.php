<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveApprovalChain;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Notifications\LeaveBalanceAdjustedNotification;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveService
{
    /**
     * Leave types supported by the system.
     */
    public const LEAVE_TYPES = [
        'annual',
        'sick',
        'bereavement',
        'family_violence',
        'parental',
        'public_holiday',
        'alternative',
        'unpaid',
        'toil',
        'other',
    ];

    /**
     * Leave types whose free-text reason + supporting document are need-to-know:
     * the medical detail of sick leave and the safety-sensitive detail of
     * family-violence leave are visible only to the employee themselves and to
     * HR (hr.leave.manage) — never to every line-manager who can merely approve.
     */
    public const SENSITIVE_LEAVE_TYPES = ['sick', 'family_violence'];

    public static function isSensitiveLeaveType(?string $type): bool
    {
        return in_array($type, self::SENSITIVE_LEAVE_TYPES, true);
    }

    public function __construct(
        private readonly PublicHolidayCalendar $holidays,
        private readonly HrLeaveAccessService $access,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Submit a leave request with balance validation.
     *
     * Checks the employee's HrLeaveBalance for the requested leave type and year.
     * If insufficient balance, the request is still created but flagged for
     * escalation. Pending hours are incremented on the balance record.
     *
     * @param  array  $data  Request attributes: leave_type, starts_at, ends_at, hours_requested, reason, supporting_doc_path
     *
     * @throws \InvalidArgumentException If leave_type is invalid or dates are malformed
     */
    public function submitRequest(User $user, array $data): HrLeaveRequest
    {
        if (! $this->access->isCurrentStaff($user)) {
            throw new \InvalidArgumentException('Leave can only be requested for current approved staff.');
        }

        $leaveType = strtolower((string) ($data['leave_type'] ?? ''));
        if (! in_array($leaveType, self::LEAVE_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported leave type '{$leaveType}'.");
        }

        try {
            $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));
            $localStartsAt = Carbon::parse($data['starts_at'], $timezone)->startOfDay();
            $localEndsAt = Carbon::parse($data['ends_at'], $timezone)->endOfDay();
            $startsAt = $localStartsAt->copy()->utc();
            $endsAt = $localEndsAt->copy()->utc();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Leave dates are invalid.');
        }

        if ($startsAt->greaterThan($endsAt)) {
            throw new \InvalidArgumentException('Leave end date must be after the start date.');
        }

        $period = $this->normalisePeriod($data['period'] ?? null);
        $hoursRequested = isset($data['hours_requested']) && (float) $data['hours_requested'] > 0
            ? (float) $data['hours_requested']
            : $this->calculateRequestedHours($user, $localStartsAt, $localEndsAt, $period);

        if ($hoursRequested <= 0) {
            throw new \InvalidArgumentException('Requested leave hours must be greater than zero.');
        }

        $hasOverlap = HrLeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($startsAt, $endsAt) {
                $query->where('starts_at', '<=', $endsAt)
                    ->where('ends_at', '>=', $startsAt);
            })
            ->exists();

        if ($hasOverlap) {
            throw new \InvalidArgumentException('Leave request overlaps with an existing pending or approved leave request.');
        }

        return DB::transaction(function () use ($user, $data, $leaveType, $period, $localStartsAt, $startsAt, $endsAt, $hoursRequested) {
            $year = $localStartsAt->year;
            $balance = $this->ensureBalanceRecord($user, $leaveType, $year);
            $before = $this->snapshotBalance($balance);

            $availableBefore = $this->calculateAvailableHours($before['balance_hours'], $before['used_hours'], $before['pending_hours']);
            $hasRosterConflict = $this->hasRosterConflict($user->id, $startsAt, $endsAt);
            $needsEscalation = $availableBefore < $hoursRequested || $hasRosterConflict;

            $approvalRoute = $this->resolveApprovalRoute($user, 1);
            $primaryApprover = $approvalRoute['approver_user_id'];
            $approvalDueAt = now()->addHours((int) $approvalRoute['escalation_after_hours']);

            $balance->pending_hours = round((float) $balance->pending_hours + $hoursRequested, 2);
            $balance->last_synced_at = now();
            $balance->updated_by = (int) ($data['created_by'] ?? $user->id);
            $balance->save();

            $request = HrLeaveRequest::create([
                'user_id' => $user->id,
                'leave_type' => $leaveType,
                'period' => $period,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'hours_requested' => $hoursRequested,
                'reason' => $data['reason'] ?? null,
                'supporting_doc_path' => $data['supporting_doc_path'] ?? null,
                'status' => 'pending',
                'submitted_at' => now(),
                'approval_due_at' => $approvalDueAt,
                'escalated_to' => $primaryApprover,
                'escalation_level' => 1,
                'escalated_at' => $needsEscalation ? now() : null,
                'created_by' => (int) ($data['created_by'] ?? $user->id),
            ]);

            $this->recordBalanceLedger(
                balance: $balance,
                before: $before,
                entryType: 'reserved',
                hoursDelta: $hoursRequested,
                source: $request,
                createdBy: (int) ($data['created_by'] ?? $user->id),
                notes: $needsEscalation
                    ? 'Pending leave reserved and escalated for review.'
                    : 'Pending leave reserved.',
            );

            if ($primaryApprover) {
                $approver = User::find($primaryApprover);
                if ($approver) {
                    try {
                        $approver->notify(new LeaveRequestNotification($request->loadMissing('user')));
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to send leave request notification', [
                            'leave_request_id' => $request->id,
                            'approver_id' => $approver->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            return $request->fresh();
        });
    }

    /**
     * Read-only preview of a request for the modal review step (handover §5.3) — engine
     * hours (PH-aware + part-day), balance impact, roster conflict, assigned approver + SLA.
     * No persistence, no balance creation.
     *
     * @return array{hours: float, period: string, available_before: float, projected_remaining: float, insufficient: bool, has_roster_conflict: bool, approver: ?string, approval_due_at: ?string}
     */
    public function previewRequest(User $user, array $data): array
    {
        if (! $this->access->isCurrentStaff($user)) {
            throw new \InvalidArgumentException('Leave can only be previewed for current approved staff.');
        }

        $leaveType = strtolower((string) ($data['leave_type'] ?? ''));
        if (! in_array($leaveType, self::LEAVE_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported leave type '{$leaveType}'.");
        }

        try {
            $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));
            $localStartsAt = Carbon::parse($data['starts_at'], $timezone)->startOfDay();
            $localEndsAt = Carbon::parse($data['ends_at'], $timezone)->endOfDay();
            $startsAt = $localStartsAt->copy()->utc();
            $endsAt = $localEndsAt->copy()->utc();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Leave dates are invalid.');
        }

        if ($startsAt->greaterThan($endsAt)) {
            throw new \InvalidArgumentException('Leave end date must be after the start date.');
        }

        $period = $this->normalisePeriod($data['period'] ?? null);
        $hours = isset($data['hours_requested']) && (float) $data['hours_requested'] > 0
            ? (float) $data['hours_requested']
            : $this->calculateRequestedHours($user, $localStartsAt, $localEndsAt, $period);

        $year = $localStartsAt->year;
        $balance = HrLeaveBalance::query()
            ->where('user_id', $user->id)
            ->where('leave_type', $leaveType)
            ->where('year', $year)
            ->first();

        if ($balance) {
            $availableBefore = $this->calculateAvailableHours(
                (float) $balance->balance_hours,
                (float) $balance->used_hours,
                (float) $balance->pending_hours,
            );
            $rawAfter = round((float) $balance->balance_hours - (float) $balance->used_hours - (float) $balance->pending_hours - $hours, 2);
        } else {
            $entitlement = (float) (config('hr.leave.default_entitlements', [])[$leaveType] ?? 0);
            $availableBefore = $entitlement;
            $rawAfter = round($entitlement - $hours, 2);
        }

        $route = $this->resolveApprovalRoute($user, 1);
        $approver = $route['approver_user_id'] ? User::find($route['approver_user_id']) : null;

        return [
            'hours' => round($hours, 2),
            'period' => $period,
            'available_before' => round((float) $availableBefore, 2),
            'projected_remaining' => $rawAfter,
            'insufficient' => $rawAfter < 0,
            'has_roster_conflict' => $this->hasRosterConflict($user->id, $startsAt, $endsAt),
            'approver' => $approver?->name,
            'approval_due_at' => now()->addHours((int) $route['escalation_after_hours'])->toDateTimeString(),
        ];
    }

    /**
     * Approve a pending leave request.
     *
     * Converts pending hours to used hours on the balance, creates a
     * StaffTimeOff record for roster integration, and notifies the employee.
     *
     *
     * @throws \LogicException If request is not in 'pending' status
     */
    public function approveRequest(HrLeaveRequest $request, User $reviewer, ?string $reviewNotes = null): HrLeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $reviewNotes) {
            $request = $this->access->currentRequest($reviewer, $request, lockForUpdate: true);
            if ($request->status !== 'pending') {
                throw new \LogicException("Cannot approve a '{$request->status}' leave request.");
            }

            $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));
            $year = Carbon::parse($request->starts_at)->setTimezone($timezone)->year;
            $requestUser = $request->user ?: User::query()->findOrFail($request->user_id);
            $balance = $this->ensureBalanceRecord(
                $requestUser,
                $request->leave_type,
                $year,
                true,
            );
            $before = $this->snapshotBalance($balance);

            $timeOff = StaffTimeOff::create([
                'hr_leave_request_id' => $request->id,
                'user_id' => $request->user_id,
                'type' => $request->leave_type,
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
                'period' => $request->period ?: 'full_day',
                'label' => ucfirst(str_replace('_', ' ', (string) $request->leave_type)),
                'notes' => $reviewNotes,
                'created_by' => $reviewer->id,
            ]);

            $hoursRequested = (float) $request->hours_requested;
            $balance->pending_hours = max((float) $balance->pending_hours - $hoursRequested, 0);
            $balance->used_hours = round((float) $balance->used_hours + $hoursRequested, 2);
            $balance->last_synced_at = now();
            $balance->updated_by = $reviewer->id;
            $balance->save();

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
                'time_off_id' => $timeOff->id,
                'approval_due_at' => null,
            ]);

            $this->recordBalanceLedger(
                balance: $balance,
                before: $before,
                entryType: 'approved',
                hoursDelta: $hoursRequested,
                source: $request,
                createdBy: $reviewer->id,
                notes: $reviewNotes,
            );

            app(HrNotificationService::class)->notifyLeaveApproved(
                $request->fresh(['reviewer', 'user']),
            );

            return $request->fresh();
        });
    }

    /**
     * Decline a pending leave request with notification.
     *
     * Releases pending hours back to available balance and notifies
     * the employee with the decline reason.
     *
     *
     * @throws \LogicException If request is not in 'pending' status
     */
    public function declineRequest(HrLeaveRequest $request, User $reviewer, string $reason): HrLeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $reason) {
            $request = $this->access->currentRequest($reviewer, $request, lockForUpdate: true);
            if ($request->status !== 'pending') {
                throw new \LogicException("Cannot decline a '{$request->status}' leave request.");
            }

            $year = Carbon::parse($request->starts_at)->year;
            $requestUser = $request->user ?: User::query()->findOrFail($request->user_id);
            $balance = $this->ensureBalanceRecord(
                $requestUser,
                $request->leave_type,
                $year,
                true,
            );
            $before = $this->snapshotBalance($balance);

            $request->update([
                'status' => 'declined',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
                'approval_due_at' => null,
            ]);

            $hoursRequested = (float) $request->hours_requested;
            $balance->pending_hours = max((float) $balance->pending_hours - $hoursRequested, 0);
            $balance->last_synced_at = now();
            $balance->updated_by = $reviewer->id;
            $balance->save();

            $this->recordBalanceLedger(
                balance: $balance,
                before: $before,
                entryType: 'released',
                hoursDelta: -$hoursRequested,
                source: $request,
                createdBy: $reviewer->id,
                notes: $reason,
            );

            // Real mail + database notification (includes the decline reason via
            // the just-written review_notes) — mirrors the approve path's
            // LeaveApprovedNotification instead of a database-only stub.
            app(HrNotificationService::class)->notifyLeaveDeclined($request->fresh(['reviewer', 'user']));

            return $request->fresh();
        });
    }

    /**
     * Calculate the current leave balance for a user, type, and year.
     *
     * Returns accrued, used, pending, and available hours. If no balance record
     * exists, returns zeroes.
     *
     * @return array{accrued: float, used: float, pending: float, available: float}
     */
    public function calculateBalance(int $userId, string $leaveType, int $year): array
    {
        $balance = HrLeaveBalance::query()
            ->where('user_id', $userId)
            ->where('leave_type', $leaveType)
            ->where('year', $year)
            ->first();

        if (! $balance) {
            $user = User::findOrFail($userId);
            $balance = $this->ensureBalanceRecord($user, $leaveType, $year);
        }

        $available = $this->calculateAvailableHours(
            (float) $balance->balance_hours,
            (float) $balance->used_hours,
            (float) $balance->pending_hours,
        );

        return [
            'accrued' => (float) $balance->accrued_hours,
            'used' => (float) $balance->used_hours,
            'pending' => (float) $balance->pending_hours,
            'available' => $available,
        ];
    }

    /**
     * Manual balance adjustment / opening balance (handover §3.3). Writes an immutable
     * ledger row so the audit trail stays complete.
     *
     * @param  string  $mode  credit | debit | set_opening
     */
    public function adjustBalance(
        User $target,
        string $leaveType,
        int $year,
        string $mode,
        float $hours,
        ?string $reason,
        User $actor,
    ): HrLeaveBalance {
        $leaveType = strtolower($leaveType);
        if (! in_array($leaveType, self::LEAVE_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported leave type '{$leaveType}'.");
        }
        if (! in_array($mode, ['credit', 'debit', 'set_opening'], true)) {
            throw new \InvalidArgumentException("Unsupported adjustment mode '{$mode}'.");
        }
        if ($hours < 0) {
            throw new \InvalidArgumentException('Adjustment hours cannot be negative.');
        }

        $result = DB::transaction(function () use ($target, $leaveType, $year, $mode, $hours, $reason, $actor) {
            $target = $this->access->currentSubject($actor, $target);
            $balance = $this->ensureBalanceRecord($target, $leaveType, $year, true);
            $before = $this->snapshotBalance($balance);

            $entryType = 'adjustment';
            if ($mode === 'credit') {
                $delta = round($hours, 2);
                $balance->balance_hours = round((float) $balance->balance_hours + $hours, 2);
                $balance->accrued_hours = round((float) $balance->accrued_hours + $hours, 2);
            } elseif ($mode === 'debit') {
                $delta = round(-min($hours, (float) $balance->balance_hours), 2);
                $balance->balance_hours = round(max((float) $balance->balance_hours - $hours, 0), 2);
            } else { // set_opening
                $delta = round($hours - (float) $balance->balance_hours, 2);
                $balance->balance_hours = round($hours, 2);
                $balance->accrued_hours = round($hours, 2);
                $entryType = 'opening';
            }

            $balance->last_synced_at = now();
            $balance->updated_by = $actor->id;
            $balance->save();

            $this->recordBalanceLedger(
                balance: $balance,
                before: $before,
                entryType: $entryType,
                hoursDelta: $delta,
                source: null,
                createdBy: $actor->id,
                notes: $reason,
            );

            return ['balance' => $balance->fresh(), 'delta' => $delta];
        });

        // It's their statutory entitlement — tell them it moved (best-effort,
        // after commit; skip self-adjustments).
        if ($actor->id !== $target->id) {
            try {
                $target->notify(new LeaveBalanceAdjustedNotification(
                    $leaveType,
                    $year,
                    (float) $result['delta'],
                    (float) $result['balance']->balance_hours,
                    $reason,
                ));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send balance-adjusted notification', [
                    'user_id' => $target->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result['balance'];
    }

    /**
     * Immutable ledger rows for a person's leave balance (handover §3.3 read side).
     *
     * @return Collection<int, HrLeaveBalanceLedger>
     */
    public function balanceLedger(int $userId, ?string $leaveType, int $year, int $limit = 200): Collection
    {
        return HrLeaveBalanceLedger::query()
            ->where('user_id', $userId)
            ->where('year', $year)
            ->when($leaveType, fn ($q) => $q->where('leave_type', $leaveType))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Cancel a pending or approved leave request.
     *
     * @param  int  $cancelledBy  User ID
     *
     * @throws \LogicException If request is already cancelled or declined
     */
    public function cancelRequest(HrLeaveRequest $request, User $cancelledBy): HrLeaveRequest
    {
        $result = DB::transaction(function () use ($request, $cancelledBy) {
            $isOwner = (int) $request->user_id === (int) $cancelledBy->getKey();
            if ($isOwner) {
                abort_unless($this->access->isCurrentStaff($cancelledBy), 404);
                $request = HrLeaveRequest::query()
                    ->whereKey($request->getKey())
                    ->where('user_id', $cancelledBy->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                abort_unless($cancelledBy->canDo('hr.leave.manage'), 403);
                $request = $this->access->request($cancelledBy, $request, true, lockForUpdate: true);
            }

            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw new \LogicException("Cannot cancel a '{$request->status}' leave request.");
            }

            $wasApproved = $request->status === 'approved';
            $year = Carbon::parse($request->starts_at)->year;
            $hours = (float) $request->hours_requested;
            $requestUser = $request->user ?: User::query()->findOrFail($request->user_id);
            $balance = $this->ensureBalanceRecord(
                $requestUser,
                $request->leave_type,
                $year,
                true,
            );
            $before = $this->snapshotBalance($balance);

            if ($request->status === 'approved' && $request->time_off_id) {
                StaffTimeOff::where('id', $request->time_off_id)->delete();
                $balance->used_hours = max((float) $balance->used_hours - $hours, 0);
            } else {
                $balance->pending_hours = max((float) $balance->pending_hours - $hours, 0);
            }
            $balance->last_synced_at = now();
            $balance->updated_by = $cancelledBy->id;
            $balance->save();

            $request->update([
                'status' => 'cancelled',
                'reviewed_by' => $cancelledBy->id,
                'reviewed_at' => now(),
                'review_notes' => 'Cancelled by user.',
                'approval_due_at' => null,
            ]);

            $this->recordBalanceLedger(
                balance: $balance,
                before: $before,
                entryType: 'cancelled',
                hoursDelta: -$hours,
                source: $request,
                createdBy: $cancelledBy->id,
                notes: 'Leave request cancelled.',
            );

            return ['request' => $request->fresh(), 'was_approved' => $wasApproved];
        });

        // Tell whoever was waiting on the request (best-effort, after commit —
        // mirrors the submit path's approver notification).
        app(HrNotificationService::class)->notifyLeaveCancelled(
            $result['request'],
            $result['was_approved'],
            $cancelledBy->id,
        );

        return $result['request'];
    }

    /**
     * Roster → HR (Direction B): a roster manager entering `leave` time off creates a
     * real, auto-approved HrLeaveRequest so the balance and ledger are written and the
     * StaffTimeOff projection is linked — instead of a bare, HR-invisible row.
     *
     * `unavailable` / `training` stay roster-only and never reach here.
     */
    public function createRosterLeave(User $target, array $data, User $actor): HrLeaveRequest
    {
        return DB::transaction(function () use ($target, $data, $actor) {
            $target = $this->access->currentSubject($actor, $target);
            $request = $this->submitRequest($target, [
                'leave_type' => $data['leave_type'] ?? 'annual',
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'reason' => $data['label'] ?? $data['notes'] ?? 'Entered via roster',
                'created_by' => $actor->id,
            ]);

            return $this->approveRequest($request, $actor, $data['notes'] ?? 'Auto-approved — entered via roster.');
        });
    }

    /**
     * HR → roster (Direction A, edit re-sync): keep the StaffTimeOff projection of an
     * already-approved request faithful when its dates / type / period change. Invoked by
     * HrLeaveRequestObserver; safe to call directly. (Does not re-run balance math —
     * hours changes on an approved request remain an explicit cancel + re-request.)
     */
    public function syncApprovedProjection(HrLeaveRequest $request): void
    {
        if ($request->status !== 'approved' || ! $request->time_off_id) {
            return;
        }

        // Load-modify-save (not a mass update) so AuditableChanges logs the
        // projection's own state change alongside the HrLeaveRequest edit.
        $timeOff = StaffTimeOff::find($request->time_off_id);
        if (! $timeOff) {
            return;
        }

        $timeOff->fill([
            'type' => $request->leave_type,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'period' => $request->period ?: 'full_day',
            'label' => ucfirst(str_replace('_', ' ', (string) $request->leave_type)),
        ])->save();
    }

    /**
     * Determine the escalation target for a leave request that exceeds balance.
     *
     * @return int|null User ID of the escalation target, or null
     */
    protected function getEscalationTarget(User $user): ?int
    {
        return $this->access->eligibleApprovers($user)
            ->sortBy(fn (User $candidate): int => match ($candidate->role) {
                'team_lead' => 0,
                'provider_manager' => 1,
                'hr' => 2,
                'admin' => 3,
                default => 4,
            })
            ->first()?->id;
    }

    /**
     * Escalate pending leave requests that are past their approval due time.
     */
    public function escalatePendingApprovals(): int
    {
        $escalatedCount = 0;

        HrLeaveRequest::query()
            ->where('status', 'pending')
            ->whereNotNull('approval_due_at')
            ->where('approval_due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $requests) use (&$escalatedCount) {
                foreach ($requests as $request) {
                    DB::transaction(function () use ($request, &$escalatedCount) {
                        /** @var HrLeaveRequest|null $locked */
                        $locked = HrLeaveRequest::query()
                            ->whereKey($request->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $locked || $locked->status !== 'pending') {
                            return;
                        }

                        if ($locked->approval_due_at && $locked->approval_due_at->isFuture()) {
                            return;
                        }

                        $requestUser = $locked->user ?: User::query()->find($locked->user_id);
                        if (! $requestUser || ! $this->access->isCurrentStaff($requestUser)) {
                            return;
                        }

                        $nextLevel = max(2, (int) ($locked->escalation_level ?? 1) + 1);
                        $route = $this->resolveApprovalRoute($requestUser, $nextLevel);
                        $approverId = $route['approver_user_id'];

                        if (! $approverId) {
                            return;
                        }

                        $dueAt = now()->addHours((int) $route['escalation_after_hours']);

                        $locked->update([
                            'escalated_to' => $approverId,
                            'escalation_level' => $nextLevel,
                            'escalated_at' => now(),
                            'approval_due_at' => $dueAt,
                        ]);

                        $approver = User::query()->find($approverId);
                        if ($approver) {
                            try {
                                $approver->notify(new LeaveRequestNotification($locked->fresh('user')));
                            } catch (\Throwable $exception) {
                                Log::warning('Failed to notify escalated leave approver', [
                                    'leave_request_id' => $locked->id,
                                    'approver_id' => $approverId,
                                    'error' => $exception->getMessage(),
                                ]);
                            }
                        }

                        $escalatedCount++;
                    });
                }
            });

        return $escalatedCount;
    }

    /**
     * @return array{
     *   pending_total: int,
     *   overdue_count: int,
     *   due_within_24h_count: int,
     *   oldest_pending_hours: float,
     *   avg_decision_hours_30d: float,
     *   pending_by_type: array<string, int>
     * }
     */
    /**
     * Hero command-band payload shared by every leave hub surface (index,
     * balances, reports) so the brand band reads identically across tabs.
     * Counts only — cheap, no N+1.
     *
     * @return array{
     *   site_count: int,
     *   awaiting_my_decision: int,
     *   on_leave_today: int,
     *   upcoming_7d: int,
     *   absence_rate: float,
     *   overdue_count: int,
     *   roster_conflicts: int,
     *   mix: array<int, array{type: string, count: int}>
     * }
     */
    public function hubHeroData(User $viewer, bool $canViewAllQueue): array
    {
        $scoped = fn () => $this->access->visibleRequests($viewer, $canViewAllQueue);

        // Assigned to me (awaiting my decision) — pending requests routed to the viewer.
        $awaiting = $scoped()
            ->where('status', 'pending')
            ->where('escalated_to', $viewer->id)
            ->count();

        $onLeaveToday = $scoped()
            ->where('status', 'approved')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->count();

        $upcoming7d = $scoped()
            ->where('status', 'approved')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->copy()->addDays(7))
            ->count();

        // On-leave mix (active today or starting within 7 days) by type — donut.
        $mix = $scoped()
            ->where('status', 'approved')
            ->where('ends_at', '>=', now()->copy()->startOfDay())
            ->where('starts_at', '<=', now()->copy()->addDays(7))
            ->selectRaw('leave_type, COUNT(*) as count')
            ->groupBy('leave_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['type' => (string) $row->leave_type, 'count' => (int) $row->count])
            ->all();

        // Absence rate (sick hours / schedulable hours, last 30 days). Scope the
        // denominator the same way as the (viewer-scoped) sick-hours numerator so
        // a personal lens (non-manager) reads its own rate, not own-sick ÷ whole-org.
        $totalActiveStaff = $canViewAllQueue
            ? $this->access->currentStaffQuery($viewer)->count()
            : (int) $this->access->isCurrentStaff($viewer);
        $sickHours = $scoped()
            ->where('status', 'approved')
            ->where('leave_type', 'sick')
            ->where('starts_at', '>=', now()->copy()->subDays(30))
            ->sum('hours_requested');
        $absenceRate = round(((float) $sickHours / max(1, $totalActiveStaff * 160)) * 100, 1);

        $sla = $this->approvalSlaSummary($viewer, $canViewAllQueue);

        // Roster conflicts — shifts overlapping a pending leave request.
        $pendingUserIds = $scoped()->where('status', 'pending')->pluck('user_id')->unique();
        $rosterConflicts = 0;
        if ($pendingUserIds->isNotEmpty()) {
            $rosterConflicts = $this->siteAccess->applyShiftScope(Shift::query(), $viewer)
                ->whereIn('user_id', $pendingUserIds)
                ->whereIn('status', ['scheduled', 'draft'])
                ->where('starts_at', '>=', now())
                ->count();
        }

        return [
            'site_count' => count($this->access->accessibleSiteIds($viewer)),
            'awaiting_my_decision' => $awaiting,
            'on_leave_today' => $onLeaveToday,
            'upcoming_7d' => $upcoming7d,
            'absence_rate' => $absenceRate,
            'overdue_count' => (int) $sla['overdue_count'],
            'roster_conflicts' => $rosterConflicts,
            'mix' => $mix,
        ];
    }

    public function approvalSlaSummary(User $viewer, bool $canManage): array
    {
        $pending = $this->access->visibleRequests($viewer, $canManage)
            ->where('status', 'pending');

        $pendingRows = (clone $pending)->get(['id', 'leave_type', 'submitted_at', 'approval_due_at']);

        $overdueCount = $pendingRows
            ->filter(fn (HrLeaveRequest $request) => $request->approval_due_at && $request->approval_due_at->isPast())
            ->count();

        $dueSoonCount = $pendingRows
            ->filter(fn (HrLeaveRequest $request) => $request->approval_due_at &&
                $request->approval_due_at->between(now(), now()->copy()->addDay())
            )
            ->count();

        $oldestPendingHours = $pendingRows
            ->filter(fn (HrLeaveRequest $request) => $request->submitted_at !== null)
            ->map(fn (HrLeaveRequest $request) => round(abs($request->submitted_at->diffInMinutes(now())) / 60, 1))
            ->max() ?? 0.0;

        $decisions = $this->access->visibleRequests($viewer, $canManage)
            ->whereIn('status', ['approved', 'declined', 'cancelled'])
            ->whereNotNull('submitted_at')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subDays(30))
            ->get(['submitted_at', 'reviewed_at']);

        // Decision latency is a magnitude — guard against signed diffs (Carbon 3)
        // and any backdated seed data that would render a nonsensical negative.
        $avgDecisionHours = $decisions->isEmpty()
            ? 0.0
            : round($decisions->avg(fn (HrLeaveRequest $request) => abs($request->submitted_at->diffInMinutes($request->reviewed_at)) / 60), 1);

        return [
            'pending_total' => $pendingRows->count(),
            'overdue_count' => $overdueCount,
            'due_within_24h_count' => $dueSoonCount,
            'oldest_pending_hours' => (float) $oldestPendingHours,
            'avg_decision_hours_30d' => (float) $avgDecisionHours,
            'pending_by_type' => $pendingRows->groupBy('leave_type')->map(fn (Collection $group) => $group->count())->toArray(),
        ];
    }

    /**
     * Server-driven, cross-page, SLA-ordered pending approvals queue with segments.
     * Replaces the page-bound `requests.data.filter(status==='pending')` so a manager's
     * bulk actions can reach pending requests that aren't on page 1.
     *
     * Segments: awaiting_my_decision · escalated_to_me · all_pending · recently_decided.
     * Each ordered overdue → due-within-24h → oldest. Capped per segment (full count kept).
     *
     * @return array<string, array{count: int, items: Collection<int, HrLeaveRequest>}>
     */
    public function pendingInbox(User $viewer, bool $canManage, int $cap = 200): array
    {
        $base = fn () => $this->access->visibleRequests($viewer, $canManage)
            ->with(['user:id,name,email', 'reviewer:id,name', 'escalatedTo:id,name']);

        // SLA urgency: nulls last, soonest due first, then oldest submitted.
        $slaOrder = fn ($q) => $q
            ->orderByRaw('approval_due_at IS NULL')
            ->orderBy('approval_due_at')
            ->orderBy('submitted_at');

        $pendingScope = fn () => $base()->where('status', 'pending');

        $segment = function ($query) use ($slaOrder, $cap): array {
            $count = (clone $query)->count();
            $items = $slaOrder($query)->limit($cap)->get();

            return ['count' => $count, 'items' => $items];
        };

        return [
            'awaiting_my_decision' => $segment(
                $pendingScope()->where('escalated_to', $viewer->id)
            ),
            'escalated_to_me' => $segment(
                $pendingScope()->where('escalated_to', $viewer->id)->where('escalation_level', '>', 1)
            ),
            'all_pending' => $segment($pendingScope()),
            'recently_decided' => $segment(
                $base()->whereIn('status', ['approved', 'declined', 'cancelled'])
                    ->whereNotNull('reviewed_at')
                    ->where('reviewed_at', '>=', now()->subDays(7))
                    ->reorder()->orderByDesc('reviewed_at')
            ),
        ];
    }

    /**
     * Batch-compute per-request roster-conflict + balance-impact context for a set of
     * requests (no N+1). Keyed by request id. Surfaces what the engine already knows so the
     * inbox/list rows can render conflict + balance badges.
     *
     * @param  Collection<int, HrLeaveRequest>  $requests
     * @return array<int, array{rosterConflict: array, balanceImpact: array|null}>
     */
    public function annotateRequestsContext(Collection $requests, User $viewer): array
    {
        if ($requests->isEmpty()) {
            return [];
        }

        $userIds = $requests->pluck('user_id')->filter()->unique()->values();
        if ($userIds->isEmpty()) {
            return [];
        }

        $minStart = $requests->min(fn ($r) => $r->starts_at);
        $maxEnd = $requests->max(fn ($r) => $r->ends_at);

        $shifts = $this->siteAccess->applyShiftScope(Shift::query(), $viewer)
            ->whereIn('user_id', $userIds->all())
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<=', $maxEnd)
            ->where('ends_at', '>=', $minStart)
            ->with('site:id,name')
            ->get(['id', 'user_id', 'site_id', 'starts_at', 'ends_at', 'status']);

        $years = $requests->map(fn ($r) => Carbon::parse($r->starts_at)->year)->unique();
        $balances = HrLeaveBalance::query()
            ->whereIn('user_id', $userIds->all())
            ->whereIn('year', $years->all())
            ->get()
            ->keyBy(fn (HrLeaveBalance $b) => $b->user_id.'|'.$b->leave_type.'|'.$b->year);

        $out = [];
        foreach ($requests as $req) {
            $year = Carbon::parse($req->starts_at)->year;

            $overlapping = $shifts->filter(fn (Shift $s) => $s->user_id === $req->user_id
                && $s->starts_at <= $req->ends_at
                && $s->ends_at >= $req->starts_at
            )->values();

            $conflictShifts = $overlapping->take(3)->map(fn (Shift $s) => [
                'site_id' => $s->site_id,
                'site_name' => $s->site?->name,
                'date' => optional($s->starts_at)->toDateString(),
                'am_pm' => ($s->starts_at && $s->starts_at->hour < 12) ? 'AM' : 'PM',
            ])->all();

            $balanceImpact = null;
            $bal = $balances->get($req->user_id.'|'.$req->leave_type.'|'.$year);
            $active = in_array($req->status, ['pending', 'approved'], true);
            if ($bal) {
                $committed = (float) $bal->used_hours + (float) $bal->pending_hours;
                $hours = (float) $req->hours_requested;
                $after = round((float) $bal->balance_hours - $committed, 2);
                $before = round($after + ($active ? $hours : 0), 2);
                $balanceImpact = [
                    'remaining_before' => $before,
                    'projected_after' => $after,
                    'insufficient' => $active && $after < 0,
                ];
            }

            $out[$req->id] = [
                'rosterConflict' => [
                    'has_conflict' => $overlapping->isNotEmpty(),
                    'count' => $overlapping->count(),
                    'shifts' => $conflictShifts,
                ],
                'balanceImpact' => $balanceImpact,
            ];
        }

        return $out;
    }

    /**
     * On-hub calendar feed (handover §3.5/§6.3): approved (solid) + pending (dashed) leave
     * overlapping the month, grouped by person, plus public-holiday shading. Built lazily
     * (only when the Calendar tab is active) to avoid loading it on every hub render.
     *
     * @param  array{site_id?: int|string|null}  $filters
     */
    public function calendarFeed(
        User $viewer,
        string $month,
        array $filters = [],
        bool $canViewAllQueue = false,
        bool $canSeeSensitive = false,
    ): array {
        try {
            $start = Carbon::parse($month.'-01')->startOfMonth();
        } catch (\Throwable) {
            $start = now()->startOfMonth();
        }
        $end = $start->copy()->endOfMonth();

        $requests = $this->access->visibleRequests($viewer, $canViewAllQueue)
            ->whereIn('status', ['approved', 'pending'])
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->when(! empty($filters['site_id']), function ($q) use ($filters, $viewer) {
                $siteId = (int) $filters['site_id'];
                if (! in_array($siteId, $this->access->accessibleSiteIds($viewer), true)) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $userIds = HrEmployeeProfile::withTrashed()
                    ->where('primary_site_id', $filters['site_id'])
                    ->pluck('user_id');
                $q->whereIn('user_id', $userIds);
            })
            ->with(['user:id,name'])
            ->orderBy('starts_at')
            ->get();

        $userIds = $requests->pluck('user_id')->filter()->unique();
        $profiles = HrEmployeeProfile::withTrashed()
            ->whereIn('user_id', $userIds->all())
            ->get(['user_id', 'primary_site_id'])
            ->keyBy('user_id');
        $siteNames = Site::query()
            ->whereIn('id', $profiles->pluck('primary_site_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $siteFor = fn ($userId) => ($sid = $profiles->get($userId)?->primary_site_id) ? ($siteNames[$sid] ?? null) : null;

        // Per-request roster-conflict + balance-impact (batch, no N+1) so a
        // detail modal opened straight from a calendar bar has the same context
        // as the Approvals queue.
        $context = $this->annotateRequestsContext($requests, $viewer);

        $entries = $requests->map(function (HrLeaveRequest $r) use ($siteFor, $context, $viewer, $canSeeSensitive) {
            $isPending = $r->status === 'pending';
            $reasonRestricted = self::isSensitiveLeaveType($r->leave_type)
                && ! $canSeeSensitive
                && $r->user_id !== $viewer->id;

            return [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'user_name' => $r->user?->name ?? 'Unknown',
                'site' => $siteFor($r->user_id),
                'leave_type' => $r->leave_type,
                'period' => $r->period ?: 'full_day',
                'status' => $r->status,
                'hours' => (float) $r->hours_requested,
                'reason' => $reasonRestricted ? null : $r->reason,
                'reason_restricted' => $reasonRestricted,
                'submitted_at' => $r->submitted_at?->toDateTimeString(),
                'hours_waiting' => $r->submitted_at ? round($r->submitted_at->diffInMinutes(now()) / 60, 1) : 0,
                'is_overdue' => $isPending && (bool) $r->approval_due_at?->isPast(),
                'due_within_24h' => $isPending && (bool) $r->approval_due_at?->between(now(), now()->copy()->addDay()),
                'roster_conflict' => $context[$r->id]['rosterConflict'] ?? ['has_conflict' => false, 'count' => 0, 'shifts' => []],
                'balance_impact' => $context[$r->id]['balanceImpact'] ?? null,
                'start' => $r->starts_at?->toDateString(),
                'end' => $r->ends_at?->toDateString(),
            ];
        })->values();

        $people = $entries->groupBy('user_id')->map(fn ($grp) => [
            'user_id' => $grp->first()['user_id'],
            'name' => $grp->first()['user_name'],
            'site' => $grp->first()['site'],
        ])->values();

        $holidays = HrPublicHoliday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get()
            ->groupBy(fn (HrPublicHoliday $h) => $h->date->toDateString())
            ->map(fn ($grp) => [
                'name' => $grp->first()->name,
                'is_national' => (bool) $grp->first()->is_national,
                'region' => $grp->first()->region,
            ]);

        return [
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'entries' => $entries,
            'people' => $people,
            'public_holidays' => $holidays,
        ];
    }

    /**
     * Public holidays as an ISO-date → name map, for the leave-request modal's
     * inline calendar (a decorative highlight + "won't come off your balance"
     * chip). The hours engine remains the authoritative source for which days
     * are actually paid; this is purely presentational. Defaults to a 14-month
     * window from the start of the current month.
     *
     * @return array<string, string>
     */
    public function publicHolidayMap(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->copy()->addMonths(14)->endOfMonth();

        return HrPublicHoliday::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn (HrPublicHoliday $h) => [$h->date->toDateString() => (string) $h->name])
            ->all();
    }

    /**
     * @return array{approver_user_id: int|null, escalation_after_hours: int}
     */
    protected function resolveApprovalRoute(User $user, int $level): array
    {
        $chain = HrLeaveApprovalChain::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('approval_level', $level)
            ->first();

        if ($chain) {
            $candidateId = $chain->delegate_user_id ?: $chain->approver_user_id;
            $candidate = $candidateId ? User::query()->find($candidateId) : null;
            if ($candidate && $this->access->isEligibleApprover($user, $candidate)) {
                return [
                    'approver_user_id' => $candidate->id,
                    'escalation_after_hours' => max(1, (int) $chain->escalation_after_hours),
                ];
            }
        }

        return [
            'approver_user_id' => $this->getEscalationTarget($user),
            'escalation_after_hours' => max(1, (int) config('hr.leave.default_escalation_after_hours', 48)),
        ];
    }

    protected function ensureBalanceRecord(User $user, string $leaveType, int $year, bool $forUpdate = false): HrLeaveBalance
    {
        if (! $this->access->isCurrentStaff($user)) {
            throw new \InvalidArgumentException('A leave balance requires current approved staff.');
        }

        $query = HrLeaveBalance::query()
            ->where('user_id', $user->id)
            ->where('leave_type', $leaveType)
            ->where('year', $year);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        $defaultEntitlements = config('hr.leave.default_entitlements', [
            'annual' => 152,
            'sick' => 80,
            'bereavement' => 24,
            'parental' => 0,
            'public_holiday' => 0,
            'unpaid' => 0,
            'toil' => 0,
            'other' => 0,
        ]);
        $carryoverCaps = config('hr.leave.carryover_caps', [
            'annual' => 80,
            'sick' => 40,
        ]);

        $opening = (float) ($defaultEntitlements[$leaveType] ?? 0);
        $carryOver = 0.0;

        $previous = HrLeaveBalance::query()
            ->where('user_id', $user->id)
            ->where('leave_type', $leaveType)
            ->where('year', $year - 1)
            ->first();

        if ($previous) {
            $previousAvailable = $this->calculateAvailableHours(
                (float) $previous->balance_hours,
                (float) $previous->used_hours,
                (float) $previous->pending_hours,
            );
            $cap = isset($carryoverCaps[$leaveType]) ? (float) $carryoverCaps[$leaveType] : $previousAvailable;
            $carryOver = min($previousAvailable, $cap);
        }

        $startingHours = round($opening + max($carryOver, 0), 2);

        return HrLeaveBalance::query()->create([
            'user_id' => $user->id,
            'leave_type' => $leaveType,
            'year' => $year,
            'balance_hours' => $startingHours,
            'accrued_hours' => $startingHours,
            'used_hours' => 0,
            'pending_hours' => 0,
            'source' => 'system',
            'last_synced_at' => now(),
            'updated_by' => $user->id,
        ]);
    }

    /**
     * Working hours for a leave range, excluding weekends AND public holidays (a stat day
     * inside the range is not charged to the balance — Holidays Act 2003). A single-day
     * request with a half-day period charges half the contracted day.
     */
    protected function calculateRequestedHours(
        User $user,
        Carbon $startsAt,
        Carbon $endsAt,
        ?string $period = null,
        ?string $region = null,
    ): float {
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->first();

        $hoursPerWeek = (float) ($profile?->hours_per_week ?: 40);
        $hoursPerDay = max(round($hoursPerWeek / 5, 2), 1);

        $day = $startsAt->copy()->startOfDay();
        $businessDays = 0;
        while ($day->lessThanOrEqualTo($endsAt)) {
            if (! $day->isWeekend() && ! $this->holidays->isPublicHoliday($day, $region)) {
                $businessDays++;
            }
            $day->addDay();
        }

        // Part-day only applies to a single charged day (validated upstream).
        if ($businessDays === 1 && in_array($period, ['half_day_am', 'half_day_pm'], true)) {
            return round($hoursPerDay / 2, 2);
        }

        return round($businessDays * $hoursPerDay, 2);
    }

    private function normalisePeriod(?string $period): string
    {
        return in_array($period, ['half_day_am', 'half_day_pm'], true) ? $period : 'full_day';
    }

    protected function hasRosterConflict(int $userId, Carbon $startsAt, Carbon $endsAt): bool
    {
        return Shift::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<=', $endsAt)
            ->where('ends_at', '>=', $startsAt)
            ->exists();
    }

    protected function calculateAvailableHours(float $balanceHours, float $usedHours, float $pendingHours): float
    {
        return max(0, round($balanceHours - $usedHours - $pendingHours, 2));
    }

    /**
     * @return array{balance_hours: float, used_hours: float, pending_hours: float}
     */
    protected function snapshotBalance(HrLeaveBalance $balance): array
    {
        return [
            'balance_hours' => (float) $balance->balance_hours,
            'used_hours' => (float) $balance->used_hours,
            'pending_hours' => (float) $balance->pending_hours,
        ];
    }

    protected function recordBalanceLedger(
        HrLeaveBalance $balance,
        array $before,
        string $entryType,
        float $hoursDelta,
        ?Model $source,
        ?int $createdBy,
        ?string $notes
    ): void {
        HrLeaveBalanceLedger::query()->create([
            'user_id' => $balance->user_id,
            'leave_type' => $balance->leave_type,
            'year' => $balance->year,
            'entry_type' => $entryType,
            'hours_delta' => round($hoursDelta, 2),
            'balance_hours_before' => $before['balance_hours'],
            'balance_hours_after' => (float) $balance->balance_hours,
            'used_hours_before' => $before['used_hours'],
            'used_hours_after' => (float) $balance->used_hours,
            'pending_hours_before' => $before['pending_hours'],
            'pending_hours_after' => (float) $balance->pending_hours,
            'source_type' => $source ? $source->getMorphClass() : null,
            'source_id' => $source?->getKey(),
            'notes' => $notes,
            'created_by' => $createdBy,
        ]);
    }
}
