<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrCourse;
use App\Models\User;

class HrCoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.training.view');
    }

    public function view(User $user, HrCourse $course): bool
    {
        return $user->canDo('hr.training.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.training.manage');
    }

    public function update(User $user, HrCourse $course): bool
    {
        return $user->canDo('hr.training.manage');
    }
}
