<?php

namespace App\Services\Clients;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

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
     * SQL shortlist of current staff who could pass canView()/canManage() for
     * this client: holders of the family-portal permission (site checked
     * later) or assigned workers. It is a superset, so callers must still
     * apply canView()/canManage() to each row it returns.
     *
     * @return Builder<User>
     */
    public function candidateStaffQuery(Client $client, bool $manage = false): Builder
    {
        $keys = $manage ? ['family_portal.manage'] : ['family_portal.viewAny', 'family_portal.manage'];

        return $this->currentStaff->currentUsersQuery()->where(fn (Builder $staff) => $staff
            ->whereHas('roles.permissions', fn (Builder $permissions) => $permissions->whereIn('permissions.key', $keys))
            ->orWhereHas('permissionOverrides', fn (Builder $permissions) => $permissions->whereIn('permissions.key', $keys)->where('permission_user.allowed', true))
            ->orWhereExists(fn ($assigned) => $assigned->selectRaw('1')->from('client_user')
                ->whereColumn('client_user.user_id', 'users.id')->where('client_user.client_id', $client->id)));
    }

    /**
     * Narrows a clients query to a superset of the clients canView() can
     * accept for this user, mirroring its permission/assignment branches.
     * canView() remains the final check.
     *
     * @param  Builder<Client>  $clients
     * @return Builder<Client>
     */
    public function scopeViewableClients(Builder $clients, User $user): Builder
    {
        if ($user->canDo('family_portal.viewAny') || $user->canDo('family_portal.manage')) {
            return $clients->whereIn('clients.site_id', $this->siteAccess->accessibleSiteIds($user, [
                'family_portal.viewAny',
                'family_portal.manage',
            ]));
        }

        return $clients->whereExists(fn ($assigned) => $assigned->selectRaw('1')->from('client_user')
            ->whereColumn('client_user.client_id', 'clients.id')->where('client_user.user_id', $user->id));
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
