<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;

class HrEmployeeProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.employees.viewAny');
    }

    public function view(User $user, HrEmployeeProfile $profile): bool
    {
        return $user->canDo('hr.employees.viewAny')
            || $user->id === $profile->user_id;
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.employees.manage');
    }

    public function update(User $user, HrEmployeeProfile $profile): bool
    {
        return $user->canDo('hr.employees.manage')
            || $user->id === $profile->user_id;
    }

    public function delete(User $user, HrEmployeeProfile $profile): bool
    {
        return $user->canDo('hr.employees.manage');
    }
}
