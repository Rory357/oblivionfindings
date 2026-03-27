<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinPettyCashFund;
use App\Models\User;

class FinPettyCashPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.petty_cash.view');
    }

    public function view(User $user, FinPettyCashFund $fund): bool
    {
        return $user->canDo('finance.petty_cash.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.petty_cash.manage');
    }

    public function addTransaction(User $user, FinPettyCashFund $fund): bool
    {
        return $user->canDo('finance.petty_cash.manage');
    }
}
