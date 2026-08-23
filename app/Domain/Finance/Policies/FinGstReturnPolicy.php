<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinGstReturn;
use App\Models\User;

class FinGstReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.tax.view');
    }

    public function view(User $user, FinGstReturn $gstReturn): bool
    {
        return $user->canDo('finance.tax.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.tax.manage');
    }

    public function file(User $user, FinGstReturn $gstReturn): bool
    {
        return $user->canDo('finance.tax.manage');
    }

    public function amend(User $user, FinGstReturn $gstReturn): bool
    {
        return $user->canDo('finance.tax.manage');
    }
}
