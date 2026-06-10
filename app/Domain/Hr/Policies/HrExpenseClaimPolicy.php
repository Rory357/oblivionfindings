<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\User;

class HrExpenseClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.expenses.view');
    }

    public function view(User $user, HrExpenseClaim $claim): bool
    {
        return $user->canDo('hr.expenses.view')
            || $user->id === $claim->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, HrExpenseClaim $claim): bool
    {
        return $user->canDo('hr.expenses.manage')
            || ($user->id === $claim->user_id && $claim->status === 'draft');
    }

    public function approve(User $user, HrExpenseClaim $claim): bool
    {
        return $user->canDo('hr.expenses.manage');
    }
}
