<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Canonical Site, staff, source-domain, and direct-object boundary for JML. */
final class HrLifecycleAccessService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SecurityDevicesAccessService $devicesAccess,
    ) {}

    /** @return Builder<HrOnboardingChecklist> */
    public function visibleOnboardingChecklists(User $viewer): Builder
    {
        return HrOnboardingChecklist::query()
            ->whereIn('employee_profile_id', $this->onboardingProfileIds($viewer));
    }

    /** @return Builder<HrOnboardingTask> */
    public function visibleOnboardingTasks(User $viewer): Builder
    {
        return HrOnboardingTask::query()
            ->whereIn('checklist_id', $this->visibleOnboardingChecklists($viewer)->select('id'));
    }

    /** @return Builder<HrOffboardingChecklist> */
    public function visibleOffboardingChecklists(User $viewer): Builder
    {
        return HrOffboardingChecklist::query()
            ->whereIn('employee_profile_id', $this->historicalProfileIds($viewer));
    }

    /** @return Builder<HrOffboardingTask> */
    public function visibleOffboardingTasks(User $viewer): Builder
    {
        return HrOffboardingTask::query()
            ->whereIn('offboarding_checklist_id', $this->visibleOffboardingChecklists($viewer)->select('id'));
    }

    /** @return Builder<HrExitInterview> */
    public function visibleInterviews(User $viewer): Builder
    {
        return HrExitInterview::query()
            ->whereIn('employee_profile_id', $this->historicalProfileIds($viewer));
    }

    /** @return Builder<HrEmployeeProfile> */
    public function currentProfiles(User $viewer): Builder
    {
        return HrEmployeeProfile::query()->whereIn('id', $this->currentProfileIds($viewer));
    }

    /** Active, Site-complete joiners; login approval is not required pre-start. */
    public function onboardingProfiles(User $viewer): Builder
    {
        return HrEmployeeProfile::query()->whereIn('id', $this->onboardingProfileIds($viewer));
    }

    /** @return Builder<HrEmployeeProfile> */
    public function historicalProfiles(User $viewer): Builder
    {
        return HrEmployeeProfile::withTrashed()
            ->whereIn('id', $this->historicalProfileIds($viewer));
    }

    /** @return Builder<User> */
    public function currentUsers(User $viewer): Builder
    {
        return User::query()->whereIn('id', $this->currentUserIds($viewer));
    }

    public function visibleOnboardingChecklist(
        User $viewer,
        HrOnboardingChecklist|int $checklist,
        bool $lockForUpdate = false,
    ): HrOnboardingChecklist {
        return $this->findVisible(
            $this->visibleOnboardingChecklists($viewer),
            $checklist instanceof HrOnboardingChecklist ? $checklist->getKey() : $checklist,
            $lockForUpdate,
        );
    }

    public function visibleOnboardingTask(
        User $viewer,
        HrOnboardingTask|int $task,
        bool $lockForUpdate = false,
    ): HrOnboardingTask {
        return $this->findVisible(
            $this->visibleOnboardingTasks($viewer),
            $task instanceof HrOnboardingTask ? $task->getKey() : $task,
            $lockForUpdate,
        );
    }

    public function visibleOffboardingChecklist(
        User $viewer,
        HrOffboardingChecklist|int $checklist,
        bool $lockForUpdate = false,
    ): HrOffboardingChecklist {
        return $this->findVisible(
            $this->visibleOffboardingChecklists($viewer),
            $checklist instanceof HrOffboardingChecklist ? $checklist->getKey() : $checklist,
            $lockForUpdate,
        );
    }

    public function visibleOffboardingTask(
        User $viewer,
        HrOffboardingTask|int $task,
        bool $lockForUpdate = false,
    ): HrOffboardingTask {
        return $this->findVisible(
            $this->visibleOffboardingTasks($viewer),
            $task instanceof HrOffboardingTask ? $task->getKey() : $task,
            $lockForUpdate,
        );
    }

    public function currentProfile(
        User $viewer,
        HrEmployeeProfile|int $profile,
        bool $lockForUpdate = false,
    ): HrEmployeeProfile {
        return $this->findVisible(
            $this->currentProfiles($viewer),
            $profile instanceof HrEmployeeProfile ? $profile->getKey() : $profile,
            $lockForUpdate,
        );
    }

    public function onboardingProfile(
        User $viewer,
        HrEmployeeProfile|int $profile,
        bool $lockForUpdate = false,
    ): HrEmployeeProfile {
        return $this->findVisible(
            $this->onboardingProfiles($viewer),
            $profile instanceof HrEmployeeProfile ? $profile->getKey() : $profile,
            $lockForUpdate,
        );
    }

    public function historicalProfile(
        User $viewer,
        HrEmployeeProfile|int $profile,
        bool $lockForUpdate = false,
    ): HrEmployeeProfile {
        return $this->findVisible(
            $this->historicalProfiles($viewer),
            $profile instanceof HrEmployeeProfile ? $profile->getKey() : $profile,
            $lockForUpdate,
        );
    }

    public function visibleInterview(
        User $viewer,
        HrExitInterview|int $interview,
        bool $lockForUpdate = false,
    ): HrExitInterview {
        return $this->findVisible(
            $this->visibleInterviews($viewer),
            $interview instanceof HrExitInterview ? $interview->getKey() : $interview,
            $lockForUpdate,
        );
    }

    public function currentUser(User $viewer, int $userId, bool $lockForUpdate = false): User
    {
        return $this->findVisible($this->currentUsers($viewer), $userId, $lockForUpdate);
    }

    /** @return list<int> */
    public function authorizedAssetIds(User $viewer): array
    {
        if (! $viewer->canDo('assets.viewAny')) {
            return [];
        }

        return $this->devicesAccess->authorizedAssetIds($viewer);
    }

    /** @return list<int> */
    public function accessibleSiteIds(User $viewer): array
    {
        return $this->siteAccess->accessibleSiteIds($viewer);
    }

    /** @param list<int> $siteIds */
    public function canAccessEverySite(User $user, array $siteIds): bool
    {
        $required = collect($siteIds)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        return $required->isNotEmpty()
            && $required->diff($this->siteAccess->accessibleSiteIds($user))->isEmpty();
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function findVisible(Builder $query, int|string $id, bool $lockForUpdate)
    {
        $query->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return list<int> */
    private function currentProfileIds(User $viewer): array
    {
        return $this->profileIds($viewer, true);
    }

    /** @return list<int> */
    private function onboardingProfileIds(User $viewer): array
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return [];
        }

        return HrEmployeeProfile::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where(fn (Builder $query) => $query
                ->whereNull('end_date')
                ->orWhereDate('end_date', '>=', today()->toDateString()))
            ->whereHas('user')
            ->get(['id', 'primary_site_id', 'secondary_site_ids'])
            ->filter(function (HrEmployeeProfile $profile) use ($siteIds): bool {
                $assignedSiteIds = collect([
                    $profile->primary_site_id,
                    ...($profile->secondary_site_ids ?? []),
                ])
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique();

                return $assignedSiteIds->isNotEmpty()
                    && $assignedSiteIds->diff($siteIds)->isEmpty();
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function historicalProfileIds(User $viewer): array
    {
        return $this->profileIds($viewer, false);
    }

    /** @return list<int> */
    private function currentUserIds(User $viewer): array
    {
        return $this->currentProfiles($viewer)
            ->pluck('user_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function profileIds(User $viewer, bool $currentOnly): array
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return [];
        }

        $users = $currentOnly
            ? $this->siteAccess->applyStaffScope(User::query()->select('users.id'), $viewer)
            : $this->siteAccess->applyHistoricalStaffSiteScope(User::query()->select('users.id'), $viewer);

        $profiles = $currentOnly ? HrEmployeeProfile::query() : HrEmployeeProfile::withTrashed();

        return $profiles
            ->whereIn('user_id', $users)
            ->get(['id', 'primary_site_id', 'secondary_site_ids'])
            ->filter(function (HrEmployeeProfile $profile) use ($siteIds): bool {
                $assignedSiteIds = collect([
                    $profile->primary_site_id,
                    ...($profile->secondary_site_ids ?? []),
                ])
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique();

                return $assignedSiteIds->isNotEmpty()
                    && $assignedSiteIds->diff($siteIds)->isEmpty();
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
