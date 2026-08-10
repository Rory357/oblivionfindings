<?php

namespace App\Policies;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItMajorIncident;
use App\Models\User;

class ItMajorIncidentPolicy
{
    public function __construct(private readonly ItWorkAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('it.view') || $user->canDo('it.manage');
    }

    public function view(User $user, ItMajorIncident $majorIncident): bool
    {
        return $majorIncident->ticket !== null
            && $this->access->canView($user, $majorIncident->ticket);
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItMajorIncident $majorIncident): bool
    {
        return $majorIncident->ticket !== null
            && $this->access->canWork($user, $majorIncident->ticket);
    }

    public function viewStatus(User $user, ItMajorIncident $majorIncident): bool
    {
        return $this->access->canViewMajorIncidentStatus($user, $majorIncident);
    }
}
