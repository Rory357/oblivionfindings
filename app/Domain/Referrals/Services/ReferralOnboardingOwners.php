<?php

namespace App\Domain\Referrals\Services;

use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;

final class ReferralOnboardingOwners
{
    public function eligible(User $candidate, ?int $siteId, bool $restricted, ?Client $target = null, ?CurrentAuthorizationReads $reads = null): bool
    {
        if (! $siteId || ! app(ClientOnboardingAccess::class)->canViewWorkflows($candidate)
            || ! ($candidate->canDo('onboarding.edit') || $candidate->canDo('clients.update'))
            || ($restricted && ! $candidate->canDo('referrals.restricted'))
            || ! app(ClientWorkerEligibility::class)->isEligible(new Client(['site_id' => $siteId]), $candidate, $reads)) {
            return false;
        }
        if ($target) {
            return $reads ? app(ClientPolicy::class)->viewFromCurrentEvidence($candidate, $target, $reads)
                : app(ClientPolicy::class)->view($candidate, $target);
        }

        // A new person cannot already have a care assignment.
        return $candidate->canDo('clients.viewAny') && in_array($siteId,
            app(UserSiteAccessService::class)->accessibleSiteIds($candidate, ['clinical.accessAllSites', 'sites.viewAll'], $reads), true);
    }
}
