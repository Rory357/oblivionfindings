<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\User;

class ComplianceObligationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('governance.compliance.view');
    }

    public function view(User $user, ComplianceObligation $obligation): bool
    {
        return $user->canDo('governance.compliance.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('governance.compliance.manage');
    }

    public function update(User $user, ComplianceObligation $obligation): bool
    {
        return $user->canDo('governance.compliance.manage');
    }

    public function delete(User $user, ComplianceObligation $obligation): bool
    {
        return $user->canDo('governance.compliance.manage');
    }

    public function complete(User $user, ComplianceObligation $obligation): bool
    {
        return $user->canDo('governance.compliance.manage');
    }

    public function uploadEvidence(User $user, ComplianceObligation $obligation): bool
    {
        return $user->canDo('governance.compliance.manage');
    }

    public function notifyIncident(User $user): bool
    {
        return $user->canDo('governance.compliance.manage');
    }
}
