<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical Site and current-staff boundary for succession planning.
 *
 * A plan belongs to one immutable Site. Candidate assessments retain that
 * plan provenance for historical reads, while new holders/candidates and
 * readiness mutations require current approved staff at the exact plan Site.
 */
class HrSuccessionAccessService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @return array<int, int> */
    public function accessibleSiteIds(User $viewer): array
    {
        return $this->siteAccess->accessibleSiteIds($viewer);
    }

    /** @return Builder<Site> */
    public function visibleSites(User $viewer): Builder
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($this->accessibleSiteIds($viewer));
    }

    public function site(User $viewer, int $siteId, bool $lockForUpdate = false): Site
    {
        $query = $this->visibleSites($viewer)->whereKey($siteId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return Builder<HrSuccessionPlan> */
    public function visiblePlans(User $viewer): Builder
    {
        $siteIds = $this->accessibleSiteIds($viewer);

        return HrSuccessionPlan::query()
            ->when(
                $siteIds === [],
                fn (Builder $query) => $query->whereRaw('1 = 0'),
                fn (Builder $query) => $query->whereIn('site_id', $siteIds),
            );
    }

    public function plan(
        User $viewer,
        HrSuccessionPlan|int $plan,
        bool $lockForUpdate = false,
    ): HrSuccessionPlan {
        $planId = $plan instanceof HrSuccessionPlan ? $plan->getKey() : $plan;
        $query = $this->visiblePlans($viewer)->whereKey($planId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function candidate(
        User $viewer,
        HrSuccessionCandidate|int $candidate,
        bool $lockForUpdate = false,
    ): HrSuccessionCandidate {
        $candidateId = $candidate instanceof HrSuccessionCandidate
            ? $candidate->getKey()
            : $candidate;
        $siteIds = $this->accessibleSiteIds($viewer);
        $query = HrSuccessionCandidate::query()
            ->whereKey($candidateId)
            ->whereHas('successionPlan', fn (Builder $planQuery) => $planQuery->whereIn('site_id', $siteIds));
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return Builder<HrEmployeeProfile> */
    public function currentProfilesAtSite(User $viewer, int $siteId): Builder
    {
        if (! in_array($siteId, $this->accessibleSiteIds($viewer), true)) {
            return HrEmployeeProfile::query()->whereRaw('1 = 0');
        }

        return $this->applyExactSiteProfileScope(
            $this->siteAccess->applyCurrentStaffProfileScope(
                HrEmployeeProfile::query(),
                $viewer,
            ),
            $siteId,
        );
    }

    /** @return Builder<HrEmployeeProfile> */
    public function currentProfiles(User $viewer): Builder
    {
        return $this->siteAccess->applyCurrentStaffProfileScope(
            HrEmployeeProfile::query(),
            $viewer,
        );
    }

    public function currentProfileAtSite(
        User $viewer,
        int $siteId,
        int $profileId,
        bool $lockForUpdate = false,
    ): HrEmployeeProfile {
        $query = $this->currentProfilesAtSite($viewer, $siteId)->whereKey($profileId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return Builder<User> */
    public function currentUsersAtSite(User $viewer, int $siteId): Builder
    {
        if (! in_array($siteId, $this->accessibleSiteIds($viewer), true)) {
            return User::query()->whereRaw('1 = 0');
        }

        return $this->siteAccess
            ->applyStaffScope(User::query(), $viewer)
            ->whereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteId): void {
                $this->applyExactSiteProfileScope($profileQuery, $siteId);
            });
    }

    /** @return Builder<User> */
    public function currentUsers(User $viewer): Builder
    {
        return $this->siteAccess->applyStaffScope(User::query(), $viewer);
    }

    public function currentUserAtSite(
        User $viewer,
        int $siteId,
        int $userId,
        bool $lockForUpdate = false,
    ): User {
        $query = $this->currentUsersAtSite($viewer, $siteId)->whereKey($userId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function currentHolder(User $viewer, HrSuccessionPlan $plan): ?User
    {
        if ($plan->current_holder_user_id === null) {
            return null;
        }

        return $this->currentUsersAtSite($viewer, (int) $plan->site_id)
            ->whereKey($plan->current_holder_user_id)
            ->first();
    }

    /** @return list<int> */
    public function profileSiteIds(HrEmployeeProfile $profile): array
    {
        return collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function profileBelongsToSite(HrEmployeeProfile $profile, int $siteId): bool
    {
        return in_array($siteId, $this->profileSiteIds($profile), true);
    }

    /** @return Builder<HrEmployeeProfile> */
    private function applyExactSiteProfileScope(Builder $query, int $siteId): Builder
    {
        return $query->where(function (Builder $siteQuery) use ($siteId): void {
            $siteQuery
                ->where($siteQuery->qualifyColumn('primary_site_id'), $siteId)
                ->orWhereJsonContains($siteQuery->qualifyColumn('secondary_site_ids'), $siteId);
        });
    }
}
