<?php

namespace App\Services\Medication;

use App\Models\User;
use App\Services\UserSiteAccessService;

/**
 * Whose witness PIN someone may reset, or remind to set (eMAR PIN-1, P11):
 * anyone, for an all-sites user; otherwise only people without as much
 * authority as a house lead. The caller also checks the reset permission and
 * that the person is in their houses.
 */
class WitnessPinResetAuthority
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function allows(User $actor, User $target): bool
    {
        if ($this->siteAccess->canBypass($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS)) {
            return true;
        }

        return ! collect([
            'medications.witness_pin.reset',
            'medications.settings.manage',
            ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        ])->contains(fn (string $key): bool => $target->canDo($key));
    }
}
