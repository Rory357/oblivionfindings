<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;

class ClientPolicy
{
    private const SITE_SCOPE_BYPASS_PERMISSIONS = [
        'clinical.accessAllSites',
        'sites.viewAll',
    ];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('clients.viewAny') || $user->canDo('clients.viewAssigned');
    }

    public function view(User $user, Client $client): bool
    {
        return $this->viewDecision($user, $client);
    }

    public function viewFromCurrentEvidence(User $user, Client $client, CurrentAuthorizationReads $reads): bool
    {
        $reads->assertActive();

        return $this->viewDecision($user, $client, $reads);
    }

    private function viewDecision(User $user, Client $client, ?CurrentAuthorizationReads $reads = null): bool
    {
        if ($user->canAccessClientPortal($client, $reads)) {
            return true;
        }

        if ($user->canDo('clients.viewAny')) {
            return $this->canAccessClientSite(
                $user,
                $client,
                self::SITE_SCOPE_BYPASS_PERMISSIONS,
                $reads,
            );
        }

        return $user->canDo('clients.viewAssigned')
            && $this->isAssigned($user, $client, $reads)
            && $this->canAccessClientSite($user, $client, [], $reads);
    }

    /**
     * Medications access is intentionally scoped and may be granted temporarily
     * via break-glass. This does not grant full client-profile access.
     */
    public function viewMedications(User $user, Client $client): bool
    {
        if ($user->canAccessClientPortal($client)) {
            return $user->canDo('medications.view');
        }

        if (! $user->canDo('medications.view')) {
            return false;
        }

        $hasMedicationOperationsAccess = collect([
            'medications.stock.update',
            'medications.audit.view',
            'medications.reports.export',
            'reports.viewAny',
        ])
            ->contains(fn (string $permission): bool => $user->canDo($permission));

        if ($hasMedicationOperationsAccess) {
            return $this->canAccessClientSite(
                $user,
                $client,
                self::SITE_SCOPE_BYPASS_PERMISSIONS,
            );
        }

        if ($user->canDo('clients.viewAny')) {
            return $this->canAccessClientSite(
                $user,
                $client,
                self::SITE_SCOPE_BYPASS_PERMISSIONS,
            );
        }

        if ($this->isAssigned($user, $client) && $this->canAccessClientSite($user, $client)) {
            return true;
        }

        if (! $user->canDo('medications.breakglass') || ! $this->canAccessClientSite($user, $client)) {
            return false;
        }

        return $client->breakGlassAccesses()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function breakGlass(User $user, Client $client): bool
    {
        return $user->canDo('medications.breakglass')
            && $this->canAccessClientSite($user, $client);
    }

    public function manageBreakGlass(User $user, Client $client): bool
    {
        return ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view'))
            && $this->canAccessClientSite($user, $client, self::SITE_SCOPE_BYPASS_PERMISSIONS);
    }

    public function reviewBreakGlass(User $user, Client $client): bool
    {
        return $user->canDo('medications.audit.view')
            && $this->canAccessClientSite($user, $client, self::SITE_SCOPE_BYPASS_PERMISSIONS);
    }

    public function create(User $user): bool
    {
        return $user->canDo('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canDo('clients.update')
            && $this->canAccessClientSite($user, $client, self::SITE_SCOPE_BYPASS_PERMISSIONS);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->canDo('clients.delete')
            && $this->canAccessClientSite($user, $client, self::SITE_SCOPE_BYPASS_PERMISSIONS);
    }

    public function manageMeals(User $user, Client $client): bool
    {
        return ($user->canDo('sites.meals.view') || $user->canDo('clients.update'))
            && $this->canAccessClientSite($user, $client, self::SITE_SCOPE_BYPASS_PERMISSIONS);
    }

    /** @param array<int, string> $bypassPermissions */
    private function canAccessClientSite(
        User $user,
        Client $client,
        array $bypassPermissions = [],
        ?CurrentAuthorizationReads $reads = null,
    ): bool {
        $siteId = is_numeric($client->site_id) && (int) $client->site_id > 0
            ? (int) $client->site_id
            : null;

        return $siteId !== null
            && in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds($user, $bypassPermissions, $reads),
                true,
            );
    }

    private function isAssigned(User $user, Client $client, ?CurrentAuthorizationReads $reads = null): bool
    {
        if ($reads) {
            return $reads->query(DB::table('client_user'))
                ->where('client_id', $client->id)->where('user_id', $user->id)->exists();
        }

        return $client->relationLoaded('supportWorkers')
            ? $client->supportWorkers->contains('id', $user->id)
            : $client->supportWorkers()->whereKey($user->id)->exists();
    }
}
