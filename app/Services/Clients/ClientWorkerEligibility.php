<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
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

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return Builder<User> */
    public function query(Client $client): Builder
    {
        return $this->queryForSite($this->siteId($client->site_id));
    }

    /** @return Builder<User> */
    public function queryForSite(?int $siteId): Builder
    {
        $query = $this->roleEligibleQuery();

        if (
            ! $siteId
            || $siteId < 1
            || ! Site::query()
                ->whereKey($siteId)
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->exists()
        ) {
            return $query->whereRaw('1 = 0');
        }

        return $this->siteAccess->applyFleetRecipientEligibility($query, $siteId);
    }

    /**
     * Current eligible staff visible to the viewer. This is used for the
     * create-client picker before a specific client record exists.
     *
     * @param  array<int, string>  $bypassPermissions
     * @return Builder<User>
     */
    public function queryForViewer(?User $viewer, array $bypassPermissions = []): Builder
    {
        $query = $this->roleEligibleQuery();
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer, $bypassPermissions);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $eligibleAtSite) use ($siteIds): void {
            foreach ($siteIds as $siteId) {
                $eligibleAtSite->orWhere(function (Builder $candidate) use ($siteId): void {
                    $this->siteAccess->applyFleetRecipientEligibility($candidate, $siteId);
                });
            }
        });
    }

    public function contains(Client $client, int $userId): bool
    {
        return $this->query($client)->whereKey($userId)->exists();
    }

    public function containsForSite(?int $siteId, int $userId): bool
    {
        return $this->queryForSite($siteId)->whereKey($userId)->exists();
    }

    public function isEligible(Client $client, User $user, ?CurrentAuthorizationReads $reads = null): bool
    {
        if ($reads) {
            // Same role/staff/date/site predicates, with explicit locks in each
            // nested EXISTS. No inference from a loaded profile or outer lock.
            $siteId = $this->siteId($client->site_id);
            if (! $siteId || ! $reads->query(Site::query())->whereKey($siteId)->active()->notArchived()->whereNull('archived_at')->exists()) {
                return false;
            }

            return $user->exists && $reads->query($this->siteAccess->applyFleetRecipientEligibility($this->roleEligibleQuery(), $siteId))
                ->whereKey($user->id)->exists();
        }

        return $user->exists
            && $this->contains($client, (int) $user->getKey());
    }

    /** @return Builder<User> */
    private function roleEligibleQuery(): Builder
    {
        return User::query()
            ->where(function (Builder $query) {
                $query->whereHas(
                    'roles',
                    fn (Builder $roles) => $roles->whereIn(
                        'name',
                        self::ASSIGNABLE_ROLE_NAMES,
                    ),
                )->orWhereIn('role', self::ASSIGNABLE_ROLE_NAMES);
            });
    }

    private function siteId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
