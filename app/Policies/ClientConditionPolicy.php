<?php

namespace App\Policies;

use App\Models\ClientCondition;
use App\Models\User;

class ClientConditionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('clients.viewAny');
    }

    public function view(User $user, ClientCondition $condition): bool
    {
        return $user->canDo('clients.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('clients.update');
    }

    public function update(User $user, ClientCondition $condition): bool
    {
        return $user->canDo('clients.update');
    }

    public function delete(User $user, ClientCondition $condition): bool
    {
        return $user->canDo('clients.update');
    }
}
