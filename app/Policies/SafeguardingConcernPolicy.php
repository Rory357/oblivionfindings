<?php

namespace App\Policies;

use App\Models\SafeguardingConcern;
use App\Models\User;

class SafeguardingConcernPolicy
{
    /**
     * Determine if the user can view any safeguarding concerns.
     */
    public function viewAny(User $user): bool
    {
        return $user->canDo('safeguarding.viewAny');
    }

    /**
     * Determine if the user can view the safeguarding concern.
     */
    public function view(User $user, SafeguardingConcern $concern): bool
    {
        // Can view if has viewAny permission
        if ($user->canDo('safeguarding.viewAny')) {
            return true;
        }

        // Can view if assigned to the concern
        if ($concern->assigned_to_user_id === $user->id) {
            return true;
        }

        // Can view if reported the concern
        if ($concern->reported_by_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can create safeguarding concerns.
     */
    public function create(User $user): bool
    {
        return $user->canDo('safeguarding.create');
    }

    /**
     * Determine if the user can update the safeguarding concern.
     */
    public function update(User $user, SafeguardingConcern $concern): bool
    {
        // Can update if has general update permission
        if ($user->canDo('safeguarding.update')) {
            return true;
        }

        // Can update if assigned to the concern
        if ($concern->assigned_to_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can investigate the safeguarding concern.
     */
    public function investigate(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.investigate');
    }

    /**
     * Determine if the user can report to external authorities.
     */
    public function reportExternal(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.report.external');
    }

    /**
     * Determine if the user can view sensitive allegations.
     */
    public function viewSensitive(User $user): bool
    {
        return $user->canDo('safeguarding.viewSensitive');
    }
}
