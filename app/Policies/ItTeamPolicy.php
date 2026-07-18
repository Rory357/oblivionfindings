<?php

namespace App\Policies;

use App\Models\ItTeam;
use App\Models\User;

class ItTeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItTeam $team): bool
    {
        return $user->canDo('it.manage');
    }
}
