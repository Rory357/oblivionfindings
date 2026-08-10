<?php

namespace App\Policies;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItChange;
use App\Models\User;

class ItChangePolicy
{
    public function __construct(private readonly ItWorkAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('it.view') || $user->canDo('it.manage');
    }

    public function view(User $user, ItChange $change): bool
    {
        return $change->ticket !== null
            && $this->access->canView($user, $change->ticket);
    }

    public function create(User $user): bool
    {
        return $user->canDo('it.manage');
    }

    public function update(User $user, ItChange $change): bool
    {
        return $change->ticket !== null
            && $this->access->canWork($user, $change->ticket);
    }
}
