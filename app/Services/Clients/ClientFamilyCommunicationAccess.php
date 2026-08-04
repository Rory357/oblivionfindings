<?php

namespace App\Services\Clients;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\User;
use App\Services\UserSiteAccessService;

class ClientFamilyCommunicationAccess
{
    public function __construct(
        private readonly ClientWorkerEligibility $workerEligibility,
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    public function canView(User $user, Client $client): bool
    {
        if (! $this->currentStaff->isCurrent($user)) {
            return false;
        }

        if ($user->canDo('family_portal.viewAny') || $user->canDo('family_portal.manage')) {
            return $this->canAccessClientSite($user, $client, [
                'family_portal.viewAny',
                'family_portal.manage',
            ]);
        }

        return $this->isAssigned($user, $client)
            && $user->canDo('progress_notes.viewAny');
    }

    public function canManage(User $user, Client $client): bool
    {
        if (! $this->currentStaff->isCurrent($user)) {
            return false;
        }

        if ($user->canDo('family_portal.manage')) {
            return $this->canAccessClientSite($user, $client, ['family_portal.manage']);
        }

        return $this->isAssigned($user, $client)
            && $user->canDo('progress_notes.viewAny')
            && ($user->canDo('progress_notes.create') || $user->canDo('progress_notes.update'));
    }

    /**
     * Stored conversation membership is historical state, not current
     * authority. Only a currently linked portal identity or a currently
     * authorized staff identity may be projected as an active participant.
     */
    public function canAppearAsParticipant(User $user, Client $client): bool
    {
        if ($user->canAccessClientPortal($client)) {
            return $this->currentStaff->historicalProfileFor($user) === null;
        }

        if (! $this->currentStaff->isCurrent($user)) {
            return false;
        }

        return $this->canView($user, $client);
    }

    private function isAssigned(User $user, Client $client): bool
    {
        $assigned = $client->relationLoaded('supportWorkers')
            ? $client->supportWorkers->contains('id', $user->id)
            : $client->supportWorkers()->whereKey($user->id)->exists();

        return $assigned
            && $this->workerEligibility->isEligible($client, $user);
    }

    /** @param array<int, string> $bypassPermissions */
    private function canAccessClientSite(User $user, Client $client, array $bypassPermissions): bool
    {
        $siteId = is_numeric($client->site_id) && (int) $client->site_id > 0
            ? (int) $client->site_id
            : null;

        return $siteId !== null
            && in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds($user, $bypassPermissions),
                true,
            );
    }
}
