<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinAccount;
use App\Models\User;

class FinAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ledger.view');
    }

    public function view(User $user, FinAccount $account): bool
    {
        return $user->canDo('finance.ledger.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ledger.manage');
    }

    public function update(User $user, FinAccount $account): bool
    {
        return $user->canDo('finance.ledger.manage');
    }

    public function delete(User $user, FinAccount $account): bool
    {
        return $user->canDo('finance.ledger.manage') && ! $account->is_system;
    }
}
