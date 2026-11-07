<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Models\User;
use App\Support\SecurityPolicy;

/**
 * Who may use a hub action (add, approve, remove, replace, edit, "Check now", a computer install).
 * One rule for the server and the screens: HubAdminController refuses with it, and the presenters
 * send `can` flags from it, so a button never shows that the server would refuse.
 */
final class HubActionRules
{
    /** Same sign-in rules as the hub wizard, plus the permission this action needs. */
    public static function allows(?User $actor, string $permission): bool
    {
        if ($actor === null
            || $actor->approved_at === null
            || $actor->email_verified_at === null
            || $actor->external_clinical_account
            || ! $actor->canDo('securityDevices.viewAny')
            || ! $actor->canDo($permission)
            || (SecurityPolicy::forceTwoFactor() && $actor->two_factor_confirmed_at === null)) {
            return false;
        }
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            if ($actor->role === $role || $actor->hasRole($role)) {
                return false;
            }
        }

        return true;
    }
}
