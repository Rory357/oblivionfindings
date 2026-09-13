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
        // Only approved or published policies that are currently effective may be attested.
        return $user->canDo('governance.policies.view')
            && in_array($policy->status, ['approved', 'published', 'active'], true)
            && (! $policy->effective_from || ! $policy->effective_from->isFuture());
    }

    public function newVersion(User $user, GovernancePolicy $policy): bool
    {
        return $user->canDo('governance.policies.manage');
    }
}
