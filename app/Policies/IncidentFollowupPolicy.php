<?php

namespace App\Policies;

use App\Models\IncidentFollowup;
use App\Models\User;

class IncidentFollowupPolicy
{
    public function view(User $user, IncidentFollowup $followup): bool
    {
        // Must be able to view the linked incident
        $incident = $followup->incident;
        if (!$incident) {
            return false;
        }

        if (!$user->can('view', $incident)) {
            return false;
        }

        // Managers/Admin can view all followups
        if ($user->canDo('incidents.followups.manage') || $user->canDo('incidents.viewAny')) {
            return true;
        }

        // Support workers: only assigned followups
        return (int) $followup->assigned_to_user_id === (int) $user->id;
    }

    public function create(User $user, IncidentFollowup $followup): bool
    {
        // Followups are created against incidents; require manage permission
        return $user->canDo('incidents.followups.manage');
    }

    public function update(User $user, IncidentFollowup $followup): bool
    {
        return $user->canDo('incidents.followups.manage');
    }

    public function complete(User $user, IncidentFollowup $followup): bool
    {
        if ($user->canDo('incidents.followups.manage')) {
            return true;
        }

        return $user->canDo('incidents.followups.complete')
            && (int) $followup->assigned_to_user_id === (int) $user->id
            && empty($followup->completed_at);
    }

    public function delete(User $user, IncidentFollowup $followup): bool
    {
        // No deletes for audit safety
        return false;
    }
}
