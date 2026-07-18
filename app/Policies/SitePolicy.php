<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('sites.viewAny')
            && ($user->hasRole('admin', 'provider_manager', 'coordinator') || ! $user->hasRole('support_worker'));
    }

    public function view(User $user, Site $site): bool
    {
        if (! $user->canDo('sites.viewAny')) {
            return false;
        }

        return $this->canAccessTenant($user, $site)
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    public function create(User $user): bool
    {
        return $user->canDo('sites.create');
    }

    public function update(User $user, Site $site): bool
    {
        return ! $site->archived
            && $user->canDo('sites.update')
            && $this->canAccessTenant($user, $site)
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->canDo('sites.archive')
            && $this->canAccessTenant($user, $site)
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    public function archive(User $user, Site $site): bool
    {
        return $user->canDo('sites.archive')
            && $this->canAccessTenant($user, $site)
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    private function canAccessTenant(User $user, Site $site): bool
    {
        if ($this->siteAccess()->isUnrestrictedPlatformUser($user)) {
            return true;
        }

        $organizationId = $user->organization_id;

        return $organizationId !== null
            && $site->tenant_id !== null
            && (int) $site->tenant_id === (int) $organizationId;
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
            return true;
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
