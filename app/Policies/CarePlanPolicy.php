<?php

namespace App\Policies;

use App\Models\CarePlan;
use App\Models\User;

class CarePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('care_plans.viewAny');
    }

    public function view(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('care_plans.create');
    }

    public function update(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.update');
    }

    public function delete(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.delete');
    }
}
