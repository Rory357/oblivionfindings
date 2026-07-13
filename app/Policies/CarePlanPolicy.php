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
        return $user->canDo('care_plans.viewAny')
            && $this->sharesOrganization($user, $carePlan);
    }

    public function create(User $user): bool
    {
        return $user->canDo('care_plans.create');
    }

    public function update(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.update')
            && $this->sharesOrganization($user, $carePlan);
    }

    public function delete(User $user, CarePlan $carePlan): bool
    {
        return $user->canDo('care_plans.delete')
            && $this->sharesOrganization($user, $carePlan);
    }

    private function sharesOrganization(User $user, CarePlan $carePlan): bool
    {
        if ($user->organization_id === null || $carePlan->organization_id === null) {
            return true;
        }

        return (int) $user->organization_id === (int) $carePlan->organization_id;
    }
}
