<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\SpendApproval;
use App\Models\User;

class SpendApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('governance.spend.view');
    }

    public function view(User $user, SpendApproval $approval): bool
    {
        return $user->canDo('governance.spend.view');
    }

    public function download(User $user, SpendApproval $approval): bool
    {
        return $this->view($user, $approval);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user)
            && $user->canDo('governance.spend.request');
    }

    public function requestAny(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, SpendApproval $approval): bool
    {
        return $this->create($user)
            && $approval->status === SpendApproval::STATUS_DRAFT
            && ((int) $approval->requested_by === (int) $user->id
                || $user->canDo('governance.spend.manageAny'));
    }

    public function submit(User $user, SpendApproval $approval): bool
    {
        return $this->update($user, $approval);
    }

    public function manageAttachments(User $user, SpendApproval $approval): bool
    {
        return $this->update($user, $approval);
    }

    public function decide(User $user, SpendApproval $approval): bool
    {
        return $this->viewAny($user)
            && $user->canDo('governance.spend.approve')
            && (int) $approval->requested_by !== (int) $user->id
            && ($approval->submitted_by === null || (int) $approval->submitted_by !== (int) $user->id);
    }

    public function decideAny(User $user): bool
    {
        return $this->viewAny($user)
            && $user->canDo('governance.spend.approve');
    }
}
