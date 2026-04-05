<?php

namespace App\Policies;

use App\Models\LegalHold;
use App\Models\User;

class LegalHoldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('privacy.manageLegalHolds');
    }

    public function view(User $user, LegalHold $hold): bool
    {
        return $user->canDo('privacy.manageLegalHolds');
    }

    public function create(User $user): bool
    {
        return $user->canDo('privacy.manageLegalHolds');
    }

    public function update(User $user, LegalHold $hold): bool
    {
        return $user->canDo('privacy.manageLegalHolds');
    }

    public function delete(User $user, LegalHold $hold): bool
    {
        return $user->canDo('privacy.manageLegalHolds');
    }
}
