<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\ActionItem;
use App\Models\User;

class ActionItemPolicy
{
    public function view(User $user, ActionItem $action): bool
    {
        return app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class)
            ->canViewActionItem($user, $action);
    }

    public function update(User $user, ActionItem $action): bool
    {
        if (! $this->view($user, $action)) {
            return false;
        }

        return $user->canDo('governance.actions.manage') || (int) $action->assigned_to === (int) $user->id;
    }
}
