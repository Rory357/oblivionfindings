<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Models\User;

class FinPurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function view(User $user, FinPurchaseOrder $purchaseOrder): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function update(User $user, FinPurchaseOrder $purchaseOrder): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function approve(User $user, FinPurchaseOrder $purchaseOrder): bool
    {
        return $user->canDo('finance.ap.manage');
    }
}
