<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('sites.viewAny');
    }

    public function view(User $user, Site $site): bool
    {
        if (! $user->canDo('sites.viewAny')) {
            return false;
        }

        return $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    public function create(User $user): bool
    {
        return $user->canDo('sites.create');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->canDo('sites.update')
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->canDo('sites.archive')
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    private function canViewType(User $user, string $type): bool
    {
        $typePermissions = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
            'residential' => 'sites.type.house.view',
        ];

        $hasTypeScopedPermissions = collect($typePermissions)
            ->contains(fn (string $permission) => $user->canDo($permission));

        if (! $hasTypeScopedPermissions) {
            return false;
        }

        return isset($typePermissions[$type]) && $user->canDo($typePermissions[$type]);
    }

    private function canAccessAssignedSite(User $user, Site $site): bool
    {
        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds($user);

        if ($accessibleSiteIds === []) {
            return true;
        }

        return in_array((int) $site->id, $accessibleSiteIds, true);
    }

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }
}
