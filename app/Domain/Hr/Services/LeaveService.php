<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveApprovalChain;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Notifications\LeaveApprovedNotification;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Models\Shift;
use App\Models\StaffTimeOff;
use App\Models\User;
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
        'parental',
        'public_holiday',
        'unpaid',
        'toil',
        'other',
    ];

    /**
     * Submit a leave request with balance validation.
     *
     * Checks the employee's HrLeaveBalance for the requested leave type and year.
     * If insufficient balance, the request is still created but flagged for
     * escalation. Pending hours are incremented on the balance record.
     *
     * @param  User   $user
     * @param  array  $data  Request attributes: leave_type, starts_at, ends_at, hours_requested, reason, supporting_doc_path
     * @return HrLeaveRequest
     *
     * @throws \InvalidArgumentException If leave_type is invalid or dates are malformed
     */
    public function submitRequest(User $user, array $data): HrLeaveRequest
    {
        $leaveType = strtolower((string) ($data['leave_type'] ?? ''));
        if (! in_array($leaveType, self::LEAVE_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported leave type '{$leaveType}'.");
        }

        try {
            $startsAt = Carbon::parse($data['starts_at'])->startOfDay();
            $endsAt = Carbon::parse($data['ends_at'])->endOfDay();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Leave dates are invalid.');
        }

        if ($startsAt->greaterThan($endsAt)) {
            throw new \InvalidArgumentException('Leave end date must be after the start date.');
        }

        $hoursRequested = isset($data['hours_requested']) && (float) $data['hours_requested'] > 0
            ? (float) $data['hours_requested']
            : $this->calculateRequestedHours($user, $startsAt, $endsAt);

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

        return DB::transaction(function () use ($user, $data, $leaveType, $startsAt, $endsAt, $hoursRequested) {
            $year = $startsAt->year;
            $tenantId = $this->resolveTenantId($user, $data['tenant_id'] ?? null);
            $balance = $this->ensureBalanceRecord($user, $leaveType, $year, false, $tenantId);
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
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'leave_type' => $leaveType,
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
     * Approve a pending leave request.
     *
     * Converts pending hours to used hours on the balance, creates a
     * StaffTimeOff record for roster integration, and notifies the employee.
     *
     * @param  HrLeaveRequest  $request
     * @param  User            $reviewer
     * @param  string|null     $reviewNotes
     * @return HrLeaveRequest
     *
     * @throws \LogicException If request is not in 'pending' status
     */
    public function approveRequest(HrLeaveRequest $request, User $reviewer, ?string $reviewNotes = null): HrLeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new \LogicException("Cannot approve a '{$request->status}' leave request.");
        }

        return DB::transaction(function () use ($request, $reviewer, $reviewNotes) {
            $year = Carbon::parse($request->starts_at)->year;
            $requestUser = $request->user ?: User::query()->findOrFail($request->user_id);
            $balance = $this->ensureBalanceRecord(
                $requestUser,
                $request->leave_type,
                $year,
                true,
                $request->tenant_id,
            );
            $before = $this->snapshotBalance($balance);

            $timeOff = StaffTimeOff::create([
                'user_id' => $request->user_id,
                'type' => $request->leave_type,
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
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

            try {
                $request->user?->notify(new LeaveApprovedNotification($request->fresh(['reviewer', 'user'])));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send leave approval notification', [
                    'leave_request_id' => $request->id,
                    'user_id' => $request->user_id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $request->fresh();
        });
    }

    /**
     * Decline a pending leave request with notification.
     *
     * Releases pending hours back to available balance and notifies
     * the employee with the decline reason.
     *
     * @param  HrLeaveRequest  $request
     * @param  User            $reviewer
     * @param  string          $reason
     * @return HrLeaveRequest
     *
     * @throws \LogicException If request is not in 'pending' status
     */
    public function declineRequest(HrLeaveRequest $request, User $reviewer, string $reason): HrLeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new \LogicException("Cannot decline a '{$request->status}' leave request.");
        }

        return DB::transaction(function () use ($request, $reviewer, $reason) {
            $year = Carbon::parse($request->starts_at)->year;
            $requestUser = $request->user ?: User::query()->findOrFail($request->user_id);
            $balance = $this->ensureBalanceRecord(
                $requestUser,
                $request->leave_type,
                $year,
                true,
                $request->tenant_id,
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

            $this->notifyUserOfDecision($request, 'declined', $reason);

            return $request->fresh();
        });
    }

    /**
     * Calculate the current leave balance for a user, type, and year.
     *
     * Returns accrued, used, pending, and available hours. If no balance record
     * exists, returns zeroes.
     *
     * @param  int     $userId
     * @param  string  $leaveType
     * @param  int     $year
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
     * Cancel a pending or approved leave request.
     *
     * @param  HrLeaveRequest  $request
     * @param  int             $cancelledBy  User ID
     * @return HrLeaveRequest
     *
     * @throws \LogicException If request is already cancelled or declined
     */
    public function cancelRequest(HrLeaveRequest $request, int $cancelledBy): HrLeaveRequest
    {
        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw new \LogicException("Cannot cancel a '{$request->status}' leave request.");
        }

        return DB::transaction(function () use ($request, $cancelledBy) {
            $year = Carbon::parse($request->starts_at)->year;
            $hours = (float) $request->hours_requested;
            $requestUser = $request->user ?: User::query()->findOrFail($request->user_id);
            $balance = $this->ensureBalanceRecord(
                $requestUser,
                $request->leave_type,
                $year,
                true,
                $request->tenant_id,
            );
            $before = $this->snapshotBalance($balance);

            if ($request->status === 'approved' && $request->time_off_id) {
                StaffTimeOff::where('id', $request->time_off_id)->delete();
                $balance->used_hours = max((float) $balance->used_hours - $hours, 0);
            } else {
                $balance->pending_hours = max((float) $balance->pending_hours - $hours, 0);
            }
            $balance->last_synced_at = now();
            $balance->updated_by = $cancelledBy;
            $balance->save();

            $request->update([
                'status' => 'cancelled',
                'reviewed_by' => $cancelledBy,
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
                createdBy: $cancelledBy,
                notes: 'Leave request cancelled.',
            );

            return $request->fresh();
        });
    }

    /**
     * Determine the escalation target for a leave request that exceeds balance.
     *
     * @param  User  $user
     * @return int|null  User ID of the escalation target, or null
     */
    protected function getEscalationTarget(User $user): ?int
    {
        $chain = HrLeaveApprovalChain::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('approval_level')
            ->first();

        if ($chain) {
            return $chain->delegate_user_id ?: $chain->approver_user_id;
        }

        $fallback = User::query()
            ->where(function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['admin', 'hr', 'provider_manager', 'team_lead']))
                    ->orWhereIn('role', ['admin', 'hr', 'provider_manager', 'team_lead']);
            })
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 WHEN role = 'hr' THEN 1 ELSE 2 END")
            ->first();

        return $fallback?->id;
    }

    /**
     * Escalate pending leave requests that are past their approval due time.
     */
    public function escalatePendingApprovals(?int $tenantId = null): int
    {
        $escalatedCount = 0;

        HrLeaveRequest::query()
            ->where('status', 'pending')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
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
                        if (! $requestUser) {
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
    public function approvalSlaSummary(?int $tenantId, ?int $viewerUserId, bool $canManage): array
    {
        $pending = HrLeaveRequest::query()
            ->where('status', 'pending')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage && $viewerUserId !== null, fn ($query) => $query->where('user_id', $viewerUserId));

        $pendingRows = (clone $pending)->get(['id', 'leave_type', 'submitted_at', 'approval_due_at']);

        $overdueCount = $pendingRows
            ->filter(fn (HrLeaveRequest $request) => $request->approval_due_at && $request->approval_due_at->isPast())
            ->count();

        $dueSoonCount = $pendingRows
            ->filter(fn (HrLeaveRequest $request) =>
                $request->approval_due_at &&
                $request->approval_due_at->between(now(), now()->copy()->addDay())
            )
            ->count();

        $oldestPendingHours = $pendingRows
            ->filter(fn (HrLeaveRequest $request) => $request->submitted_at !== null)
            ->map(fn (HrLeaveRequest $request) => round($request->submitted_at->diffInMinutes(now()) / 60, 1))
            ->max() ?? 0.0;

        $decisions = HrLeaveRequest::query()
            ->whereIn('status', ['approved', 'declined', 'cancelled'])
            ->whereNotNull('submitted_at')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subDays(30))
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage && $viewerUserId !== null, fn ($query) => $query->where('user_id', $viewerUserId))
            ->get(['submitted_at', 'reviewed_at']);

        $avgDecisionHours = $decisions->isEmpty()
            ? 0.0
            : round($decisions->avg(fn (HrLeaveRequest $request) => $request->submitted_at->diffInMinutes($request->reviewed_at) / 60), 1);

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
            return [
                'approver_user_id' => $chain->delegate_user_id ?: $chain->approver_user_id,
                'escalation_after_hours' => max(1, (int) $chain->escalation_after_hours),
            ];
        }

        return [
            'approver_user_id' => $this->getEscalationTarget($user),
            'escalation_after_hours' => max(1, (int) config('hr.leave.default_escalation_after_hours', 48)),
        ];
    }

    protected function ensureBalanceRecord(User $user, string $leaveType, int $year, bool $forUpdate = false, ?int $tenantId = null): HrLeaveBalance
    {
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
        $resolvedTenantId = $this->resolveTenantId($user, $tenantId);

        return HrLeaveBalance::create([
            'tenant_id' => $resolvedTenantId,
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

    protected function resolveTenantId(User $user, mixed $tenantId = null): int
    {
        if (is_numeric($tenantId)) {
            return (int) $tenantId;
        }

        $directTenantId = $user->getAttribute('tenant_id');
        if (is_numeric($directTenantId)) {
            return (int) $directTenantId;
        }

        $profileTenantId = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');

        if (is_numeric($profileTenantId)) {
            return (int) $profileTenantId;
        }

        $fallbackTenantId = HrLeaveBalance::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->value('tenant_id')
            ?? HrLeaveRequest::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->value('tenant_id')
            ?? HrEmployeeProfile::query()->orderBy('id')->value('tenant_id')
            ?? HrLeaveRequest::query()->orderBy('id')->value('tenant_id')
            ?? HrLeaveBalance::query()->orderBy('id')->value('tenant_id');

        return (int) ($fallbackTenantId ?? 1);
    }

    protected function calculateRequestedHours(User $user, Carbon $startsAt, Carbon $endsAt): float
    {
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->first();

        $hoursPerWeek = (float) ($profile?->hours_per_week ?: 40);
        $hoursPerDay = max(round($hoursPerWeek / 5, 2), 1);

        $day = $startsAt->copy()->startOfDay();
        $businessDays = 0;
        while ($day->lessThanOrEqualTo($endsAt)) {
            if (! $day->isWeekend()) {
                $businessDays++;
            }
            $day->addDay();
        }

        return round($businessDays * $hoursPerDay, 2);
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
        HrLeaveBalanceLedger::create([
            'tenant_id' => $balance->tenant_id,
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

    protected function notifyUserOfDecision(HrLeaveRequest $request, string $decision, ?string $reason = null): void
    {
        $user = $request->user;
        if (! $user) {
            return;
        }

        $payload = [
            'type' => "leave_{$decision}",
            'leave_request_id' => $request->id,
            'leave_type' => $request->leave_type,
            'starts_at' => optional($request->starts_at)->toIso8601String(),
            'ends_at' => optional($request->ends_at)->toIso8601String(),
            'reason' => $reason,
            'action_url' => "/hr/leave/{$request->id}",
        ];

        try {
            $user->notify(new class($payload) extends \Illuminate\Notifications\Notification {
                public function __construct(private readonly array $payload) {}

                public function via(object $notifiable): array
                {
                    return ['database'];
                }

                public function toArray(object $notifiable): array
                {
                    return $this->payload;
                }
            });
        } catch (\Throwable $exception) {
            Log::warning('Failed to send leave decision notification', [
                'leave_request_id' => $request->id,
                'decision' => $decision,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
