<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinVendor;
use App\Models\User;

class FinVendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function view(User $user, FinVendor $vendor): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function update(User $user, FinVendor $vendor): bool
    {
        return $user->canDo('finance.ap.manage');
    }
}
