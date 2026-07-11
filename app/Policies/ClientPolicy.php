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

        // If they have a global view permission (manager/admin), allow — but only
        // for clients in their own organization. This is the per-record tenancy
        // guard: there is NO global organization scope on the Client model, so
        // without this check a manager/admin in org A could view any client in
        // org B simply by holding `clients.viewAny`.
        if ($user->canDo('clients.viewAny') && ($user->hasRole('admin', 'manager', 'coordinator') || ! $user->hasRole('support_worker'))) {
            return $this->sharesOrganization($user, $client);
        }

        // Assigned-only access
        if ($user->canDo('clients.viewAssigned')) {
            return $this->sharesOrganization($user, $client)
                && $client->supportWorkers()->whereKey($user->id)->exists();
        }

        // Support workers can view only assigned clients (legacy)
        return $user->hasRole('support_worker')
            && $this->sharesOrganization($user, $client)
            && $client->supportWorkers()->whereKey($user->id)->exists();
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

        // Org-wide medication ops access ($hasMedicationOpsAccess already implies
        // `medications.view`) — still confined to the user's own organization.
        if ($hasMedicationOpsAccess) {
            return $this->sharesOrganization($user, $client);
        }

        // Managers/admins: global within their own organization.
        if ($user->canDo('clients.viewAny') && ($user->hasRole('admin', 'manager', 'coordinator') || ! $user->hasRole('support_worker'))) {
            return $user->canDo('medications.view') && $this->sharesOrganization($user, $client);
        }

        // Assigned-only access.
        $assigned = $client->relationLoaded('supportWorkers')
            ? $client->supportWorkers->contains('id', $user->id)
            : $client->supportWorkers()->whereKey($user->id)->exists();
        if ($assigned && $this->sharesOrganization($user, $client)) {
            return $user->canDo('medications.view');
        }

        // Break-glass (temporary) for meds-only access.
        if ($user->canDo('medications.breakglass') && $this->sharesOrganization($user, $client)) {
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

    public function breakGlass(User $user, Client $client): bool
    {
        return $user->canDo('medications.breakglass')
            && $this->sharesOrganization($user, $client);
    }

    public function manageBreakGlass(User $user, Client $client): bool
    {
        return ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view'))
            && $this->sharesOrganization($user, $client);
    }

    public function reviewBreakGlass(User $user, Client $client): bool
    {
        return $user->canDo('medications.audit.view')
            && $this->sharesOrganization($user, $client);
    }

    public function create(User $user): bool
    {
        return $user->canDo('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canDo('clients.update') && $this->sharesOrganization($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        // create this permission if you want it
        return $user->canDo('clients.delete') && $this->sharesOrganization($user, $client);
    }

    public function manageMeals(User $user, Client $client): bool
    {
        return ($user->canDo('sites.meals.view') || $user->canDo('clients.update'))
            && $this->sharesOrganization($user, $client);
    }

    /**
     * Per-record multi-tenancy guard for the "global" access branches above.
     *
     * Organization isolation is opt-in and the `organization_id` columns on both
     * `users` and `clients` are nullable (single-tenant and "lighter schema"
     * deployments may leave them unset; see User::getOrganizationIdAttribute,
     * which falls back to 1 when the column is absent). We therefore only *deny*
     * when both sides carry a concrete, differing organization. When either side
     * is null we stay permissive so single-tenant installs are unaffected, while
     * a populated mismatch (org A user vs org B client) is blocked. Values are
     * cast to int because the client column is uncast and may arrive as a string
     * from the driver, whereas the user accessor returns an int.
     */
    protected function sharesOrganization(User $user, Client $client): bool
    {
        $userOrg = $user->organization_id;
        $clientOrg = $client->organization_id;

        if ($userOrg === null || $clientOrg === null) {
            return true;
        }

        return (int) $userOrg === (int) $clientOrg;
    }
}
