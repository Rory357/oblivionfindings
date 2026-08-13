<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinBankAccount;
use App\Models\User;

class FinBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.bank.view');
    }

    public function view(User $user, FinBankAccount $bankAccount): bool
    {
        return $user->canDo('finance.bank.view')
            && (int) $user->organization_id === (int) $bankAccount->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.bank.manage');
    }

    public function update(User $user, FinBankAccount $bankAccount): bool
    {
        return $user->canDo('finance.bank.manage')
            && (int) $user->organization_id === (int) $bankAccount->organization_id;
    }
}
