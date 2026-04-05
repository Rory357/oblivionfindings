<?php

namespace App\Policies;

use App\Models\DataBreachLog;
use App\Models\User;

class DataBreachLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('privacy.reportBreaches');
    }

    public function view(User $user, DataBreachLog $log): bool
    {
        return $user->canDo('privacy.reportBreaches');
    }

    public function create(User $user): bool
    {
        return $user->canDo('privacy.reportBreaches');
    }

    public function update(User $user, DataBreachLog $log): bool
    {
        return $user->canDo('privacy.reportBreaches');
    }

    public function delete(User $user, DataBreachLog $log): bool
    {
        return $user->canDo('privacy.reportBreaches');
    }
}
