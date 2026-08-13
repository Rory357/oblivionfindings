<?php

namespace App\Policies;

use App\Models\ConsentRequest;
use App\Models\User;

class ConsentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('consents.viewAny');
    }

    public function view(User $user, ConsentRequest $request): bool
    {
        return $user->canDo('consents.viewAny')
            && $this->matchesCanonicalSite($request)
            && $user->can('view', $request->client);
    }

    public function create(User $user): bool
    {
        return $user->canDo('consents.request');
    }

    public function cancel(User $user, ConsentRequest $request): bool
    {
        return $user->canDo('consents.request')
            && $this->matchesCanonicalSite($request)
            && $user->can('view', $request->client);
    }

    public function respond(User $user, ConsentRequest $request): bool
    {
        if ($request->recipient_user_id !== $user->id) {
            return false;
        }

        return $this->matchesCanonicalSite($request)
            && ConsentRequest::recipientRoleMatchesRelationship($user, $request->recipient_relationship)
            && $user->canAccessClientPortal($request->client);
    }

    private function matchesCanonicalSite(ConsentRequest $request): bool
    {
        return is_numeric($request->site_id)
            && is_numeric($request->client?->site_id)
            && (int) $request->site_id === (int) $request->client->site_id;
    }
}
