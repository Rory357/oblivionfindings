<?php

namespace App\Policies;

use App\Models\ItProblem;
use App\Models\User;

class ItProblemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('it.view');
    }

    public function view(User $user, ItProblem $problem): bool
    {
        return $user->canDo('it.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItProblem $problem): bool
    {
        return $user->canDo('it.manage');
    }
}
