<?php

namespace App\Policies;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItProblem;
use App\Models\User;

class ItProblemPolicy
{
    public function __construct(private readonly ItWorkAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('it.view') || $user->canDo('it.manage');
    }

    public function view(User $user, ItProblem $problem): bool
    {
        return $problem->ticket !== null
            && $this->access->canView($user, $problem->ticket);
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItProblem $problem): bool
    {
        return $problem->ticket !== null
            && $this->access->canWork($user, $problem->ticket);
    }
}
