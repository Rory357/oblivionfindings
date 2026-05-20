<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\GovernancePolicy;
use App\Models\User;

class GovernancePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('governance.policies.view');
    }

    public function view(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('governance.policies.manage');
    }

    public function update(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.manage');
    }

    public function delete(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.manage');
    }

    public function approve(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.manage');
    }

    public function attest(User $user, GovernancePolicy $policy): bool
    {
        // Any user with view permission may attest to a policy applicable to them.
        return $user->canDo('governance.policies.view');
    }

    public function newVersion(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.manage');
    }
}
