<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinBill;
use App\Models\User;

class FinBillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function view(User $user, FinBill $bill): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function update(User $user, FinBill $bill): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function approve(User $user, FinBill $bill): bool
    {
        return $user->canDo('finance.ap.manage');
    }
}
