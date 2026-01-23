<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        // Anyone with one of these permissions can access the list route.
        // Row-level filtering is handled in the query/controller.
        return $user->canDo('clients.viewAny') || $user->canDo('clients.viewAssigned');
    }

    public function view(User $user, Client $client): bool
    {
        // Client portal users (the client themselves or next of kin) can view their client
        if ($user->hasRole('client', 'next_of_kin') && $user->canAccessClientPortal($client)) {
            return true;
        }

        // If they have a global view permission (manager/admin), allow
        if ($user->canDo('clients.viewAny') && !$user->hasRole('support_worker')) {
            return true;
        }

        // Assigned-only access
        if ($user->canDo('clients.viewAssigned')) {
            return $client->supportWorkers()->whereKey($user->id)->exists();
        }

        // Support workers can view only assigned clients (legacy)
        return $user->hasRole('support_worker') && $client->supportWorkers()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->canDo('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canDo('clients.update');
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->canDo('clients.delete'); // create this permission if you want it
    }
}
