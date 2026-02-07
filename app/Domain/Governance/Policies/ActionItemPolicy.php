<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\ActionItem;
use App\Models\User;

class ActionItemPolicy
{
    public function view(User $user, ActionItem $action): bool
    {
        return $user->canDo('governance.actions.view');
    }

    public function update(User $user, ActionItem $action): bool
    {
        return $user->canDo('governance.actions.manage') || $action->assigned_to === $user->id;
    }
}
