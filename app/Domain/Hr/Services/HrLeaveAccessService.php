<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            && ($candidate->canDo('hr.leave.approve') || $candidate->canDo('hr.leave.manage'))
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
