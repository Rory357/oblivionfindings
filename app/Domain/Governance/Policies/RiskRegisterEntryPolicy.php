<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\User;

class RiskRegisterEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'ceo', 'coo', 'compliance_lead', 'risk_lead');
    }

    public function view(User $user, RiskRegisterEntry $risk): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'ceo', 'coo', 'compliance_lead', 'risk_lead');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'ceo', 'coo', 'compliance_lead', 'risk_lead');
    }

    public function update(User $user, RiskRegisterEntry $risk): bool
    {
        // Risk owner can update
        if ($user->id === $risk->risk_owner_id) {
            return true;
        }

        // Admin and executives can update
        return $user->hasRole('admin', 'ceo', 'coo', 'compliance_lead', 'risk_lead');
    }

    public function delete(User $user, RiskRegisterEntry $risk): bool
    {
        return $user->hasRole('admin', 'ceo', 'risk_lead');
    }

    public function accept(User $user, RiskRegisterEntry $risk): bool
    {
        // Board approval required for above-appetite risks
        return $user->hasRole('admin', 'board_chair', 'board_member');
    }

    public function close(User $user, RiskRegisterEntry $risk): bool
    {
        return $user->hasRole('admin', 'ceo', 'coo', 'compliance_lead', 'risk_lead');
    }
}
