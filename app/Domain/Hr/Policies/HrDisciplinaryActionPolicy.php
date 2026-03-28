<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Models\User;

class HrDisciplinaryActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.cases.view');
    }

    public function view(User $user, HrDisciplinaryAction $action): bool
    {
        return $user->canDo('hr.cases.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.disciplinary.manage');
    }

    public function update(User $user, HrDisciplinaryAction $action): bool
    {
        return $user->canDo('hr.disciplinary.manage');
    }
}
