<?php

namespace App\Policies;

use App\Models\ItQueue;
use App\Models\User;

class ItQueuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItQueue $queue): bool
    {
        return $user->canDo('it.manage');
    }
}
