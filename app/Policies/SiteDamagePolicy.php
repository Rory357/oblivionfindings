<?php

namespace App\Policies;

use App\Models\SiteDamage;
use App\Models\User;

class SiteDamagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('sites.damages.view');
    }

    public function view(User $user, SiteDamage $siteDamage): bool
    {
        return $user->canDo('sites.damages.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('sites.damages.create');
    }

    public function update(User $user, SiteDamage $siteDamage): bool
    {
        return $user->canDo('sites.damages.manage');
    }

    public function delete(User $user, SiteDamage $siteDamage): bool
    {
        return $user->canDo('sites.damages.manage');
    }
}
