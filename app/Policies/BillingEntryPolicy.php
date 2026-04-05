<?php

namespace App\Policies;

use App\Models\BillingEntry;
use App\Models\User;

class BillingEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('billing.viewAny');
    }

    public function view(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('billing.create');
    }

    public function update(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.create');
    }

    public function approve(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.approve');
    }

    public function delete(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.approve');
    }
}
