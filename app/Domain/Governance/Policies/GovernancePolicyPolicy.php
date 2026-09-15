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
        // Read and confirm: only approved policies that ask for confirmation and
        // whose effective date (NZ calendar date) has arrived.
        return $user->canDo('governance.policies.view')
            && $policy->needsConfirmation()
            && $policy->isInEffect();
    }

    public function newVersion(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.manage');
    }
}
