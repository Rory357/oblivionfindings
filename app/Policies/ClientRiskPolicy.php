<?php

namespace App\Policies;

use App\Models\ClientRisk;
use App\Models\User;

class ClientRiskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('risks.viewAny') || $user->canDo('risks.viewAssigned');
    }

    public function view(User $user, ClientRisk $risk): bool
    {
        if ($user->canDo('risks.viewAny')) {
            return true;
        }

        if (!$user->canDo('risks.viewAssigned')) {
            return false;
        }

        return $risk->client()
            ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->canDo('risks.create');
    }

    public function update(User $user, ClientRisk $risk): bool
    {
        return $user->canDo('risks.update');
    }

    public function delete(User $user, ClientRisk $risk): bool
    {
        return $user->canDo('risks.delete');
    }
}
