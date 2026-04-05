<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('sites.viewAny');
    }

    public function view(User $user, Site $site): bool
    {
        if (!$user->canDo('sites.viewAny')) {
            return false;
        }

        return $this->canViewType($user, $site->type);
    }

    public function create(User $user): bool
    {
        return $user->canDo('sites.create');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->canDo('sites.update');
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->canDo('sites.archive');
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

        if (!$hasTypeScopedPermissions) {
            return false;
        }

        return isset($typePermissions[$type]) && $user->canDo($typePermissions[$type]);
    }
}
