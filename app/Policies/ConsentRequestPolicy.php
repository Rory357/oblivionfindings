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
        return $user->canDo('consents.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->canDo('consents.request');
    }

    public function cancel(User $user, ConsentRequest $request): bool
    {
        return $user->canDo('consents.request') && $request->isPending();
    }

    /**
     * Gate for family-portal respondents. Doesn't use a role permission — the
     * user must be the designated recipient AND have portal access to the
     * client. Checked in the portal controller.
     */
    public function respond(User $user, ConsentRequest $request): bool
    {
        if (!$request->isActionable()) {
            return false;
        }

        if ($request->recipient_user_id !== $user->id) {
            return false;
        }

        return $user->canAccessClientPortal($request->client);
    }
}
