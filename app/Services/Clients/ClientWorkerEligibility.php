<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ClientWorkerEligibility
{
    private const ASSIGNABLE_ROLE_NAMES = [
        'support_worker',
        'team_lead',
        'coordinator',
        'clinical_lead',
        'provider_manager',
    ];

    /**
     * Staff who may be assigned to this client.
     *
     * Portal identities are excluded by User::staff(). When both records carry
     * an organisation, assignments are confined to that organisation.
     *
     * @return Builder<User>
     */
    public function query(Client $client): Builder
    {
        return $this->queryForOrganization($client->organization_id);
    }

    /** @return Builder<User> */
    public function queryForOrganization(?int $organizationId): Builder
    {
        return User::query()
            ->staff()
            ->where(function (Builder $query) {
                $query->whereHas(
                    'roles',
                    fn (Builder $roles) => $roles->whereIn(
                        'name',
                        self::ASSIGNABLE_ROLE_NAMES,
                    ),
                )->orWhereIn('role', self::ASSIGNABLE_ROLE_NAMES);
            })
            ->when(
                $organizationId !== null,
                fn (Builder $query) => $query->where(
                    'organization_id',
                    $organizationId,
                ),
            );
    }

    public function contains(Client $client, int $userId): bool
    {
        return $this->query($client)->whereKey($userId)->exists();
    }

    public function isEligible(Client $client, User $user): bool
    {
        if (
            $client->organization_id !== null
            && $user->organization_id !== null
            && (int) $client->organization_id !== (int) $user->organization_id
        ) {
            return false;
        }

        return $user->hasRole(...self::ASSIGNABLE_ROLE_NAMES)
            || in_array($user->role, self::ASSIGNABLE_ROLE_NAMES, true);
    }
}
