<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\InitiativeBudget;
use App\Models\User;

class InitiativeBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.budget.view') || $user->canDo('roadmap.view');
    }

    public function view(User $user, InitiativeBudget $budget): bool
    {
        return ($user->canDo('roadmap.budget.view') || $user->canDo('roadmap.view'))
            && $this->sameTenant($user, $budget->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.budget.manage');
    }

    public function update(User $user, InitiativeBudget $budget): bool
    {
        return $user->canDo('roadmap.budget.manage') && $this->sameTenant($user, $budget->tenant_id);
    }

    protected function sameTenant(User $user, ?int $tenantId): bool
    {
        if ($tenantId === null || $user->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $tenantId;
    }
}
