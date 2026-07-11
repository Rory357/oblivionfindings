<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;

class ClientFamilyCommunicationAccess
{
    public function __construct(
        private readonly ClientWorkerEligibility $workerEligibility,
    ) {}

    public function canView(User $user, Client $client): bool
    {
        if (! $this->sharesOrganization($user, $client)) {
            return false;
        }

        if ($user->canDo('family_portal.viewAny') || $user->canDo('family_portal.manage')) {
            return true;
        }

        return $this->isAssigned($user, $client)
            && $user->canDo('progress_notes.viewAny');
    }

    public function canManage(User $user, Client $client): bool
    {
        if (! $this->sharesOrganization($user, $client)) {
            return false;
        }

        if ($user->canDo('family_portal.manage')) {
            return true;
        }

        return $this->isAssigned($user, $client)
            && $user->canDo('progress_notes.viewAny')
            && ($user->canDo('progress_notes.create') || $user->canDo('progress_notes.update'));
    }

    private function isAssigned(User $user, Client $client): bool
    {
        $assigned = $client->relationLoaded('supportWorkers')
            ? $client->supportWorkers->contains('id', $user->id)
            : $client->supportWorkers()->whereKey($user->id)->exists();

        return $assigned
            && $this->workerEligibility->isEligible($client, $user);
    }

    private function sharesOrganization(User $user, Client $client): bool
    {
        $userOrganizationId = $user->organization_id;
        $clientOrganizationId = $client->organization_id;

        if ($userOrganizationId === null || $clientOrganizationId === null) {
            return true;
        }

        return (int) $userOrganizationId === (int) $clientOrganizationId;
    }
}
