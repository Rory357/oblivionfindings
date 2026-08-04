<?php

namespace App\Policies;

use App\Models\CarePlan;
use App\Models\User;
use App\Services\UserSiteAccessService;

class CarePlanPolicy
{
    private const CLIENT_SITE_BYPASS_PERMISSIONS = ['clients.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('care_plans.viewAny');
    }

    public function view(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.viewAny')
            && $this->canAccessClientSite($user, $carePlan);
    }

    public function create(User $user): bool
    {
        return $user->canDo('care_plans.create');
    }

    public function update(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.update')
            && $this->canAccessClientSite($user, $carePlan);
    }

    public function delete(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.delete')
            && $this->canAccessClientSite($user, $carePlan);
    }

    private function canAccessClientSite(User $user, CarePlan $carePlan): bool
    {
        if ($this->siteAccess->canBypass($user, self::CLIENT_SITE_BYPASS_PERMISSIONS)) {
            return true;
        }

        $client = $carePlan->client()
            ->with('site:id')
            ->first(['id', 'site_id']);

        if (! $client?->site) {
            return false;
        }

        return in_array(
            (int) $client->site_id,
            $this->siteAccess->accessibleSiteIds($user),
            true,
        );
    }
}
