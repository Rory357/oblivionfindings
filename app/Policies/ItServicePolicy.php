<?php

namespace App\Policies;

use App\Models\ItService;
use App\Models\User;

class ItServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItService $service): bool
    {
        return $user->canDo('it.manage');
    }
}
