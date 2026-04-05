<?php

namespace App\Policies;

use App\Models\ClientAssessment;
use App\Models\User;

class ClientAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('clients.viewAny') || $user->canDo('clients.viewAssigned');
    }

    public function view(User $user, ClientAssessment $assessment): bool
    {
        if ($user->canDo('clients.viewAny')) {
            return true;
        }

        if (!$user->canDo('clients.viewAssigned')) {
            return false;
        }

        return $assessment->client()
            ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->canDo('clients.update');
    }

    public function update(User $user, ClientAssessment $assessment): bool
    {
        return $user->canDo('clients.update');
    }

    public function delete(User $user, ClientAssessment $assessment): bool
    {
        return $user->canDo('clients.update');
    }
}
