<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinInvoice;
use App\Models\User;

class FinInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ar.view');
    }

    public function view(User $user, FinInvoice $invoice): bool
    {
        return $user->canDo('finance.ar.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ar.manage');
    }

    public function update(User $user, FinInvoice $invoice): bool
    {
        return $user->canDo('finance.ar.manage');
    }
}
