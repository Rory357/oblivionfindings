<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\User;

class ClientIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');
    }

    public function view(User $user, ClientIncident $incident): bool
    {
        if ($user->canDo('incidents.viewAny')) {
            return true;
        }

        if (!$user->canDo('incidents.viewAssigned')) {
            return false;
        }

        return $incident->client()
            ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
            ->exists();
    }

    public function create(User $user, Client $client): bool
    {
        return $user->canDo('incidents.create') && $user->can('view', $client);
    }

    public function update(User $user, ClientIncident $incident): bool
    {
        // Locked once closed (immutability for audit).
        if ($incident->status === 'closed') {
            return false;
        }

        if ($user->canDo('incidents.viewAny') && $user->canDo('incidents.update')) {
            return true;
        }

        return $user->canDo('incidents.update') && $incident->isEditableByReporter($user);
    }

    public function submit(User $user, ClientIncident $incident): bool
    {
        if (!$user->canDo('incidents.submit')) {
            return false;
        }

        return !$incident->isShiftLinked()
            && (int) $incident->reported_by === (int) $user->id
            && empty($incident->submitted_at)
            && $incident->status === 'draft';
    }

    public function review(User $user, ClientIncident $incident): bool
    {
        return $user->canDo('incidents.approve') && $incident->status === 'submitted';
    }

    public function close(User $user, ClientIncident $incident): bool
    {
        return $user->canDo('incidents.approve') && $incident->status === 'reviewed';
    }

    public function reopen(User $user, ClientIncident $incident): bool
    {
        // Reopening is an elevated action (audit-sensitive).
        return $user->canDo('incidents.reopen') && $incident->status === 'closed';
    }

    public function export(User $user): bool
    {
        return $user->canDo('incidents.export');
    }

    public function delete(User $user, ClientIncident $incident): bool
    {
        return false;
    }
}
