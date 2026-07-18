<?php

namespace App\Policies;

use App\Models\ItMajorIncident;
use App\Models\User;

class ItMajorIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('it.view');
    }

    public function view(User $user, ItMajorIncident $majorIncident): bool
    {
        return $user->canDo('it.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItMajorIncident $majorIncident): bool
    {
        return $user->canDo('it.manage');
    }

    public function viewStatus(User $user, ItMajorIncident $majorIncident): bool
    {
        return $user->canDo('it.request') || $user->canDo('it.view');
    }
}
