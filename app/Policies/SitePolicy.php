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
        return $user->canDo('sites.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('sites.create');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->canDo('sites.update');
    }
}
