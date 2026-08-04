<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\Initiative;
use App\Models\User;

class InitiativePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function view(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.manage');
    }

    public function update(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.manage');
    }

    public function delete(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.manage');
    }

    public function approve(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.approve');
    }

    public function score(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.manage');
    }
}
