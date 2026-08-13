<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinBankReconciliation;
use App\Models\User;

class FinBankReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.bank.view');
    }

    public function view(User $user, FinBankReconciliation $reconciliation): bool
    {
        return $user->canDo('finance.bank.view')
            && (int) $user->organization_id === (int) $reconciliation->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.bank.manage');
    }

    public function complete(User $user, FinBankReconciliation $reconciliation): bool
    {
        return $user->canDo('finance.bank.manage')
            && (int) $user->organization_id === (int) $reconciliation->organization_id;
    }
}
