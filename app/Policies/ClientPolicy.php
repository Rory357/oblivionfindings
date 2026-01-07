<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        // Anyone with this permission can access the list route
        return $user->canDo('clients.viewAny');
    }

    public function view(User $user, Client $client): bool
    {
        // If they have a global view permission (manager/admin), allow
        if ($user->canDo('clients.viewAny') && !$user->hasRole('support_worker')) {
            return true;
        }

        // Support workers can view only assigned clients
        return $user->hasRole('support_worker')
            && $client->supportWorkers()->whereKey($user->id)->exists();
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
