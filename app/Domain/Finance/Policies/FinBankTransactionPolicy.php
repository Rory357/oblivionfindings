<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinBankTransaction;
use App\Models\User;

class FinBankTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.bank.view');
    }

    public function view(User $user, FinBankTransaction $bankTransaction): bool
    {
        return $user->canDo('finance.bank.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.bank.manage');
    }

    public function update(User $user, FinBankTransaction $bankTransaction): bool
    {
        return $user->canDo('finance.bank.manage');
    }
}
