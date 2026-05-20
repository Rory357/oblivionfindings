<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\StrategicPlan;
use App\Models\User;

class StrategicPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('governance.strategy.view');
    }

    public function view(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('governance.strategy.manage');
    }

    public function update(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.manage');
    }

    public function delete(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.manage');
    }

    public function approve(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.manage');
    }

    public function addGoal(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.manage');
    }

    public function createVersion(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.manage');
    }

    public function viewChanges(User $user, StrategicPlan $plan): bool
    {
        return $user->canDo('governance.strategy.view');
    }
}
