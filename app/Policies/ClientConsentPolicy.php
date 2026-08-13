<?php

namespace App\Policies;

use App\Models\ClientConsent;
use App\Models\User;

class ClientConsentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('consents.viewAny');
    }

    public function view(User $user, ClientConsent $consent): bool
    {
        return $user->canDo('consents.viewAny')
            && $this->matchesCanonicalSite($consent)
            && $user->can('view', $consent->client);
    }

    public function create(User $user): bool
    {
        return $user->canDo('consents.record');
    }

    public function update(User $user, ClientConsent $consent): bool
    {
        return $user->canDo('consents.manage')
            && $this->matchesCanonicalSite($consent)
            && $user->can('view', $consent->client);
    }

    public function delete(User $user, ClientConsent $consent): bool
    {
        return $user->canDo('consents.manage')
            && $this->matchesCanonicalSite($consent)
            && $user->can('view', $consent->client);
    }

    public function withdraw(User $user, ClientConsent $consent): bool
    {
        return ($user->canDo('consents.withdraw') || $user->canDo('consents.manage'))
            && $this->matchesCanonicalSite($consent)
            && $user->can('view', $consent->client);
    }

    private function matchesCanonicalSite(ClientConsent $consent): bool
    {
        return is_numeric($consent->site_id)
            && is_numeric($consent->client?->site_id)
            && (int) $consent->site_id === (int) $consent->client->site_id;
    }
}
