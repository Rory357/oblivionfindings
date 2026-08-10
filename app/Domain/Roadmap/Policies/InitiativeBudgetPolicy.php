<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\InitiativeBudget;
use App\Models\User;

class InitiativeBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.budget.view') || $user->canDo('roadmap.view');
    }

    public function view(User $user, InitiativeBudget $budget): bool
    {
        return $user->canDo('roadmap.budget.view') || $user->canDo('roadmap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.budget.manage');
    }

    public function update(User $user, InitiativeBudget $budget): bool
    {
        return $user->canDo('roadmap.budget.manage');
    }
}
