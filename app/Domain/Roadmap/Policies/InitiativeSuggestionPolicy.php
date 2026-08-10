<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Models\User;

class InitiativeSuggestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function view(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function update(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.manage');
    }

    public function approve(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.approve');
    }

    public function reject(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.approve');
    }
}
