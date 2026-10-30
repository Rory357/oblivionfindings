<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Canonical ownership, current-staff, and Site boundary for Leave.
 *
 * Leave history is retained against a person and remains visible to managers
 * only while that person has provenance at one of their approved Sites. New
 * requests, adjustments, approval actions, routes, and notifications require
 * current approved staff. Self-service is exact-owner only and former staff
 * cannot recover access through a retained leave row.
 */
final class HrLeaveAccessService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCurrentStaffService $currentStaff,
        private readonly AuthorizationEvidenceLockService $authorization,
    ) {}

    /** @return list<int> */
    public function accessibleSiteIds(User $viewer): array
    {
        return $this->siteAccess->accessibleSiteIds($viewer);
    }

    /** @return Builder<User> */
    public function currentStaffQuery(User $viewer): Builder
    {
        return $this->siteAccess->applyStaffScope(User::query(), $viewer);
    }

    /** @return Builder<User> */
    public function historicalStaffQuery(User $viewer): Builder
    {
        return $this->siteAccess->applyHistoricalStaffSiteScope(User::query(), $viewer);
    }

    public function isCurrentStaff(User|int $staff): bool
    {
        return $this->currentStaff->isCurrent($staff);
    }

    public function currentSubject(User $viewer, User|int $staff, bool $allowSelf = true): User
    {
        $staffId = $staff instanceof User ? $staff->getKey() : $staff;
        if ($allowSelf && (int) $staffId === (int) $viewer->getKey()) {
            abort_unless($this->currentStaff->isCurrent($viewer), 404);

            return $viewer;
        }

        return $this->currentStaffQuery($viewer)->findOrFail($staffId);
    }

    public function historicalSubject(User $viewer, User|int $staff, bool $allowSelf = true): User
    {
        $staffId = $staff instanceof User ? $staff->getKey() : $staff;
        if ($allowSelf && (int) $staffId === (int) $viewer->getKey()) {
            abort_unless($this->currentStaff->isCurrent($viewer), 404);

            return $viewer;
        }

        return $this->historicalStaffQuery($viewer)->findOrFail($staffId);
    }

    /** @return Builder<HrLeaveRequest> */
    public function visibleRequests(User $viewer, bool $canViewQueue): Builder
    {
        $query = HrLeaveRequest::query();

        if (! $canViewQueue) {
            return $this->currentStaff->isCurrent($viewer)
                ? $query->where('user_id', $viewer->getKey())
                : $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'user_id',
            $this->historicalStaffQuery($viewer)->select('users.id'),
        );
    }

    /** @return Builder<HrLeaveBalance> */
    public function visibleBalances(User $viewer, bool $canViewOthers): Builder
    {
        $query = HrLeaveBalance::query();

        if (! $canViewOthers) {
            return $this->currentStaff->isCurrent($viewer)
                ? $query->where('user_id', $viewer->getKey())
                : $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'user_id',
            $this->historicalStaffQuery($viewer)->select('users.id'),
        );
    }

    public function request(
        User $viewer,
        HrLeaveRequest|int $request,
        bool $canViewQueue,
        bool $lockForUpdate = false,
    ): HrLeaveRequest {
        $requestId = $request instanceof HrLeaveRequest ? $request->getKey() : $request;
        $query = $this->visibleRequests($viewer, $canViewQueue)->whereKey($requestId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function currentRequest(
        User $viewer,
        HrLeaveRequest|int $request,
        bool $lockForUpdate = false,
    ): HrLeaveRequest {
        $requestId = $request instanceof HrLeaveRequest ? $request->getKey() : $request;
        $query = HrLeaveRequest::query()
            ->whereIn('user_id', $this->currentStaffQuery($viewer)->select('users.id'))
            ->whereKey($requestId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function canReviewLeave(User $viewer): bool
    {
        return $viewer->canDo('hr.leave.approve') || $viewer->canDo('hr.leave.manage');
    }

    public function reviewRequest(
        User $viewer,
        HrLeaveRequest|int $request,
        bool $lockForUpdate = false,
    ): HrLeaveRequest {
        abort_unless($this->canReviewLeave($viewer), 403);

        return $this->currentRequest($viewer, $request, $lockForUpdate);
    }

    /**
     * Final command evidence, acquired after all aggregate waits. Taking these
     * shared locks before cover Shifts would invert Shift-first roster writers.
     */
    public function lockCurrentReviewActor(User $actor, HrLeaveRequest $request): User
    {
        return $this->lockCurrentCommandActor($actor, $request, 'review');
    }

    public function lockCurrentCancellationActor(User $actor, HrLeaveRequest $request): User
    {
        return $this->lockCurrentCommandActor($actor, $request, 'cancel');
    }

    private function lockCurrentCommandActor(User $actor, HrLeaveRequest $request, string $command): User
    {
        try {
            $actor = $this->authorization->lockForUserWithoutWaiting($actor, ['hr.leave.approve', 'hr.leave.manage']);
            abort_unless($actor->isApproved(), 403);
            $isSelfCancellation = $command === 'cancel' && (int) $request->user_id === (int) $actor->id;
            if (! $isSelfCancellation) {
                abort_unless($command === 'review' ? $this->canReviewLeave($actor) : $actor->canDo('hr.leave.manage'), 403);
            }

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $request, $command, $isSelfCancellation): User {
                // ensureBalanceRecord already requires a current approved
                // subject. Retain that predicate even for manager cancellation.
                $subject = $this->currentStaff->currentUsersQuery()->whereKey($request->user_id);
                if (! $isSelfCancellation) {
                    $siteIds = $this->siteAccess->accessibleSiteIds($actor, [], $reads);
                    $profiles = HrEmployeeProfile::query()->select('user_id');
                    if ($command === 'cancel') {
                        // Cancellation retains the existing historical Site
                        // scope, alongside the current-staff balance guard.
                        $profiles->withTrashed();
                    }
                    $profiles->where(function (Builder $sites) use ($siteIds): void {
                        $sites->whereIn('primary_site_id', $siteIds);
                        foreach ($siteIds as $siteId) {
                            $sites->orWhereJsonContains('secondary_site_ids', $siteId);
                        }
                    });
                    // whereIn compiles its subquery immediately into an SQL
                    // expression. Register its current read before that point.
                    $subject->whereIn('users.id', $reads->query($profiles));
                }
                // EXISTS subqueries, including profile and role exclusions,
                // are current locking reads rather than an earlier RR snapshot.
                $reads->query($subject)->firstOrFail(['users.id']);

                return $actor;
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'leave_request' => 'Leave access is being updated. Please try again.',
            ]);
        }
    }

    public function staffShareSite(User|int $first, User|int $second): bool
    {
        $firstSites = $this->siteIdsFor($first);
        $secondSites = $this->siteIdsFor($second);

        return $firstSites !== []
            && $secondSites !== []
            && array_intersect($firstSites, $secondSites) !== [];
    }

    public function isEligibleApprover(User $subject, User $candidate): bool
    {
        return $subject->getKey() !== $candidate->getKey()
            && $this->currentStaff->isCurrent($subject)
            && $this->currentStaff->isCurrent($candidate)
            && $this->canReviewLeave($candidate)
            && $this->staffShareSite($subject, $candidate);
    }

    /** @return Collection<int, User> */
    public function eligibleApprovers(User $subject): Collection
    {
        if (! $this->currentStaff->isCurrent($subject)) {
            return collect();
        }

        return $this->currentStaff->currentUsersQuery()
            ->where('users.id', '!=', $subject->getKey())
            ->with(['roles.permissions', 'permissionOverrides'])
            ->get()
            ->filter(fn (User $candidate): bool => $this->isEligibleApprover($subject, $candidate))
            ->values();
    }

    /** @return list<int> */
    private function siteIdsFor(User|int $staff): array
    {
        $staffId = $staff instanceof User ? $staff->getKey() : $staff;
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $staffId)
            ->first(['primary_site_id', 'secondary_site_ids']);

        if (! $profile) {
            return [];
        }

        return collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values()
            ->all();
    }
}
