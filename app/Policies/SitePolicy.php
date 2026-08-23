<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Auth\Access\Response;

class SitePolicy
{
    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function viewAny(User $user): bool
    {
        return $user->canDo('sites.viewAny')
            && ($user->hasRole('admin', 'provider_manager', 'coordinator') || ! $user->hasRole('support_worker'));
    }

    public function view(User $user, Site $site): Response
    {
        return $this->objectAction($user, $site, 'sites.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('sites.create');
    }

    public function update(User $user, Site $site): Response
    {
        return $this->objectAction($user, $site, 'sites.update', denyWhenArchived: true);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->canDo('sites.archive')
            && $this->canViewType($user, $site->type)
            && $this->canAccessAssignedSite($user, $site);
    }

    public function archive(User $user, Site $site): Response
    {
        return $this->objectAction($user, $site, 'sites.archive');
    }

    private function objectAction(
        User $user,
        Site $site,
        string $permission,
        bool $denyWhenArchived = false,
    ): Response {
        if (! $this->canViewType($user, $site->type)
            || ! $this->canAccessAssignedSite($user, $site)
        ) {
            return Response::denyAsNotFound();
        }

        if ($denyWhenArchived && $site->archived) {
            return Response::deny();
        }

        return $user->canDo($permission)
            ? Response::allow()
            : Response::deny();
    }

    private function canViewType(User $user, ?string $type): bool
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
        if ($this->siteAccess()->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return true;
        }

        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds($user);

        return in_array((int) $site->id, $accessibleSiteIds, true);
    }

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }
}
