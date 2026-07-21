<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItServiceIdentity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\LegacyStorageContext;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ItServiceIdentityCredentialService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItApiWorkItemService $workItems,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{identity: ItServiceIdentity, secret: string, token: string}
     */
    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $manager = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $executionAccount = User::query()
                ->whereKey((int) $data['actor_user_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->guardManager($manager);
            $this->guardExecutionAccount($executionAccount);
            $this->guardDelegatedScope($manager, $executionAccount, $data);

            $publicId = Str::lower(Str::random(20));
            $secret = Str::random(64);
            $token = "ofi_{$publicId}_{$secret}";
            $identity = ItServiceIdentity::query()->create([
                'tenant_id' => LegacyStorageContext::id(),
                'actor_user_id' => (int) $data['actor_user_id'],
                'created_by_user_id' => $manager->id,
                'public_id' => $publicId,
                'token_hash' => hash('sha256', $secret),
                ...Arr::only($data, [
                    'name', 'description', 'abilities', 'allowed_work_types',
                    'allowed_site_ids', 'allowed_fields', 'require_signature',
                    'rate_limit_per_minute', 'expires_at',
                ]),
            ]);

            AuditLogger::logOrFail('it.api.identity.created', $identity, [
                'application_scope' => 'single_installation',
                'actor_id' => $manager->id,
                'abilities' => $identity->abilities,
                'allowed_work_types' => $identity->allowed_work_types,
                'allowed_site_ids' => $identity->allowed_site_ids,
                'require_signature' => $identity->require_signature,
            ]);

            return ['identity' => $identity->fresh('actor'), 'secret' => $secret, 'token' => $token];
        });
    }

    public function revoke(ItServiceIdentity $identity, User $actor): ItServiceIdentity
    {
        return DB::transaction(function () use ($identity, $actor): ItServiceIdentity {
            $manager = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->guardManager($manager);
            $locked = ItServiceIdentity::query()
                ->with('actor')
                ->whereKey($identity->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $this->canManageLoadedIdentity($manager, $locked)) {
                throw new DomainException('The service identity is not accessible to this manager.');
            }

            if ($locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $manager->id,
                ])->save();
                AuditLogger::logOrFail('it.api.identity.revoked', $locked, [
                    'application_scope' => 'single_installation',
                    'actor_id' => $manager->id,
                ]);
            }

            return $locked->refresh();
        });
    }

    public function canManage(User $manager, ItServiceIdentity $identity): bool
    {
        try {
            $this->guardManager($manager);
        } catch (DomainException) {
            return false;
        }

        $identity->loadMissing('actor');

        return $this->canManageLoadedIdentity($manager, $identity);
    }

    /** @return Collection<int, User> */
    public function delegableExecutionAccounts(User $manager): Collection
    {
        try {
            $this->guardManager($manager);
        } catch (DomainException) {
            return collect();
        }

        $managerSiteIds = $this->workAccess->approvedSiteIds($manager);

        return ItStaffDirectory::agents()
            ->filter(function (User $candidate) use ($manager, $managerSiteIds): bool {
                if (! $this->workItems->isCurrentExecutionAccount($candidate)) {
                    return false;
                }

                return $candidate->is($manager)
                    || array_intersect($managerSiteIds, $this->workAccess->approvedSiteIds($candidate)) !== [];
            })
            ->values();
    }

    private function guardManager(User $actor): void
    {
        if (! $this->workItems->isCurrentExecutionAccount($actor)) {
            throw new DomainException('You are not allowed to manage IT service identities.');
        }
    }

    private function guardExecutionAccount(User $executionAccount): void
    {
        if (! $this->workItems->isCurrentExecutionAccount($executionAccount)
            || ! ItStaffDirectory::agents()->contains(
                fn (User $user): bool => $user->id === $executionAccount->id,
            )) {
            throw new DomainException('The execution account must be a current IT agent.');
        }
    }

    /** @param array<string, mixed> $data */
    private function guardDelegatedScope(User $manager, User $executionAccount, array $data): void
    {
        $managerSiteIds = $this->workAccess->approvedSiteIds($manager);
        $executionSiteIds = $this->workAccess->approvedSiteIds($executionAccount);
        foreach (array_unique(array_map('intval', (array) ($data['allowed_site_ids'] ?? []))) as $siteId) {
            if (! in_array($siteId, $managerSiteIds, true)
                || ! in_array($siteId, $executionSiteIds, true)) {
                throw new DomainException('Service identity Sites must be approved for both the manager and execution account.');
            }
        }

        $abilities = (array) ($data['abilities'] ?? []);
        foreach ([
            'work:sensitive' => 'it.viewSensitive',
            'work:organisation-wide' => 'it.organisationWide',
        ] as $ability => $permission) {
            if (in_array($ability, $abilities, true)
                && (! $manager->canDo($permission) || ! $executionAccount->canDo($permission))) {
                throw new DomainException('Exceptional API abilities require matching authority on both accounts.');
            }
        }
    }

    private function canManageLoadedIdentity(User $manager, ItServiceIdentity $identity): bool
    {
        if ((int) $identity->created_by_user_id !== (int) $manager->id
            && (int) $identity->actor_user_id !== (int) $manager->id) {
            return false;
        }

        $managerSiteIds = $this->workAccess->approvedSiteIds($manager);
        foreach ((array) $identity->allowed_site_ids as $siteId) {
            if (! is_numeric($siteId) || ! in_array((int) $siteId, $managerSiteIds, true)) {
                return false;
            }
        }

        return (! in_array('work:sensitive', (array) $identity->abilities, true)
                || $manager->canDo('it.viewSensitive'))
            && (! in_array('work:organisation-wide', (array) $identity->abilities, true)
                || $manager->canDo('it.organisationWide'));
    }
}
