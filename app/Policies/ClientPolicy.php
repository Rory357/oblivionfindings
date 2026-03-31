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
        if ($user->canDo('clients.viewAny') && ($user->hasRole('admin', 'manager', 'coordinator') || !$user->hasRole('support_worker'))) {
            return true;
        }

        // Assigned-only access
        if ($user->canDo('clients.viewAssigned')) {
            return $client->supportWorkers()->whereKey($user->id)->exists();
        }

        // Support workers can view only assigned clients (legacy)
        return $user->hasRole('support_worker') && $client->supportWorkers()->whereKey($user->id)->exists();
    }

    /**
     * Medications access is intentionally scoped and may be granted temporarily
     * via break-glass. This should NOT automatically grant full client profile
     * access.
     */
    public function viewMedications(User $user, Client $client): bool
    {
        $hasMedicationOpsAccess =
            $user->canDo('medications.view')
            && (
                $user->canDo('medications.stock.update')
                || $user->canDo('medications.audit.view')
                || $user->canDo('medications.reports.export')
                || $user->canDo('reports.viewAny')
            );

        // Portal roles can view meds only via the portal rules.
        if ($user->hasRole('client', 'next_of_kin') && $user->canAccessClientPortal($client)) {
            return $user->canDo('medications.view');
        }

        if ($hasMedicationOpsAccess) {
            return true;
        }

        // Managers/admins: global.
        if ($user->canDo('clients.viewAny') && ($user->hasRole('admin', 'manager', 'coordinator') || !$user->hasRole('support_worker'))) {
            return $user->canDo('medications.view');
        }

        // Assigned-only access.
        $assigned = $client->supportWorkers()->whereKey($user->id)->exists();
        if ($assigned) {
            return $user->canDo('medications.view');
        }

        // Break-glass (temporary) for meds-only access.
        if ($user->canDo('medications.breakglass')) {
            $has = $client->breakGlassAccesses()
                ->where('user_id', $user->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();
            if ($has) {
                return $user->canDo('medications.view');
            }
        }

        return false;
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
