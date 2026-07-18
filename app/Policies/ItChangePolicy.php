<?php

namespace App\Policies;

use App\Models\ItChange;
use App\Models\User;

class ItChangePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('it.view');
    }

    public function view(User $user, ItChange $change): bool
    {
        return $user->canDo('it.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItChange $change): bool
    {
        return $user->canDo('it.manage');
    }
}
