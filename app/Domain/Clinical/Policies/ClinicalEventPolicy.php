<?php

namespace App\Domain\Clinical\Policies;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Models\User;

class ClinicalEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('clinical.events.viewAny')
            || $user->canDo('clinical.events.viewAssigned');
    }

    public function view(User $user, ClinicalEvent $event): bool
    {
        return $user->canDo('clinical.events.viewAny')
            || $user->canDo('clinical.events.viewAssigned');
    }

    public function create(User $user): bool
    {
        return $user->canDo('clinical.events.record');
    }

    public function review(User $user): bool
    {
        return $user->canDo('clinical.events.review');
    }
}
