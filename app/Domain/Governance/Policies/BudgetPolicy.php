<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('governance.budgets.view');
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->canDo('governance.budgets.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('governance.budgets.create');
    }

    public function update(User $user, Budget $budget): bool
    {
        return $user->canDo('governance.budgets.create');
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->canDo('governance.budgets.create');
    }

    public function propose(User $user, Budget $budget): bool
    {
        return $user->canDo('governance.budgets.submit');
    }

    public function approve(User $user, Budget $budget): bool
    {
        return $user->canDo('governance.budgets.approve');
    }
}
