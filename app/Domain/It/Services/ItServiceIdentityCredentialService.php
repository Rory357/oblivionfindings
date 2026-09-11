<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItServiceIdentity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            $users = User::query()
                ->whereIn('id', [$actor->id, (int) $data['actor_user_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()->keyBy('id');
            $users = app(AuthorizationEvidenceLockService::class)->lockForUsers($users, ['*']);
            $this->workAccess->lockApprovedSiteEvidenceForUsers($users);
            $manager = $users->get($actor->id);
            $executionAccount = $users->get((int) $data['actor_user_id']);
            abort_unless($manager && $executionAccount, 404);
            $this->guardManager($manager, true);
            $this->guardExecutionAccount($executionAccount, true);
            $this->guardDelegatedScope($manager, $executionAccount, $data, true);

            $publicId = Str::lower(Str::random(20));
            $secret = Str::random(64);
            $token = "ofi_{$publicId}_{$secret}";
            $identity = ItServiceIdentity::query()->create([
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
            $users = User::query()
                ->whereIn('id', [$actor->id, $identity->actor_user_id, $identity->created_by_user_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $manager = $users->get($actor->id);
            abort_unless($manager, 404);
            $locked = ItServiceIdentity::query()
                ->with('actor')
                ->whereKey($identity->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $locked->actor_user_id === (int) $identity->actor_user_id
                && (int) $locked->created_by_user_id === (int) $identity->created_by_user_id, 409);
            $users = app(AuthorizationEvidenceLockService::class)->lockForUsers($users, ['*']);
            $this->workAccess->lockApprovedSiteEvidenceForUsers($users);
            $manager = $users->get($actor->id);
            $this->guardManager($manager, true);
            if (! $this->canManageLoadedIdentity($manager, $locked, true)) {
                throw new DomainException('The service identity is not accessible to this manager.');
            }

            if ($locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $manager->id,
                    'configuration_version' => (int) $locked->configuration_version + 1,
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

    public function guardManager(User $actor, bool $locking = false): void
    {
        if (! $this->workItems->isCurrentExecutionAccount($actor, $locking)
            || in_array($actor->role, ['client', 'next_of_kin'], true)
            || $actor->hasRole('client') || $actor->hasRole('next_of_kin')) {
            throw new DomainException('You are not allowed to manage IT service identities.');
        }
    }

    private function guardExecutionAccount(User $executionAccount, bool $locking = false): void
    {
        if (! $this->workItems->isCurrentExecutionAccount($executionAccount, $locking)
            || in_array($executionAccount->role, ['client', 'next_of_kin'], true)
            || $executionAccount->hasRole('client') || $executionAccount->hasRole('next_of_kin')) {
            throw new DomainException('The execution account must be a current IT agent.');
        }
    }

    /** @param array<string, mixed> $data */
    private function guardDelegatedScope(User $manager, User $executionAccount, array $data, bool $locking = false): void
    {
        $errors = $this->delegationErrors($manager, $executionAccount, $data, $locking);
        if ($errors !== []) {
            throw new DomainException((string) reset($errors));
        }
    }

    /** Field-specific validation using the same current evidence as the write. */
    public function validateDelegatedInput(User $manager, User $executionAccount, array $data): void
    {
        $this->assertManagementTransaction();
        try {
            $this->guardExecutionAccount($executionAccount, true);
        } catch (DomainException) {
            throw ValidationException::withMessages(['actor_user_id' => 'Choose a currently approved IT execution account.']);
        }
        $errors = $this->delegationErrors($manager, $executionAccount, $data, true);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function delegationErrors(User $manager, User $executionAccount, array $data, bool $locking): array
    {
        $errors = [];
        if ($locking) {
            $this->workAccess->lockApprovedSiteEvidenceForUsers([$manager, $executionAccount]);
        }
        $managerSiteIds = $this->workAccess->approvedSiteIds($manager, $locking);
        $executionSiteIds = $this->workAccess->approvedSiteIds($executionAccount, $locking);
        foreach (array_unique(array_map('intval', (array) ($data['allowed_site_ids'] ?? []))) as $index => $siteId) {
            if (! in_array($siteId, $managerSiteIds, true)
                || ! in_array($siteId, $executionSiteIds, true)) {
                $errors["allowed_site_ids.{$index}"] = 'Service identity Sites must be approved for both the manager and execution account.';
            }
        }

        $abilities = (array) ($data['abilities'] ?? []);
        foreach ([
            'work:sensitive' => 'it.viewSensitive',
            'work:organisation-wide' => 'it.organisationWide',
        ] as $ability => $permission) {
            if (in_array($ability, $abilities, true)
                && (! $manager->canDo($permission) || ! $executionAccount->canDo($permission))) {
                $errors['abilities'] = 'Exceptional API abilities require matching authority on both accounts.';
            }
        }

        return $errors;
    }

    public function canManageLoadedIdentity(User $manager, ItServiceIdentity $identity, bool $locking = false): bool
    {
        if ((int) $identity->created_by_user_id !== (int) $manager->id
            && (int) $identity->actor_user_id !== (int) $manager->id) {
            return false;
        }

        $managerSiteIds = $this->workAccess->approvedSiteIds($manager, $locking);
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

    /** Caller owns the ordered users, identity and current authorization locks. */
    public function updateGrants(ItServiceIdentity $identity, User $manager, User $executionAccount, array $data): ItServiceIdentity
    {
        $this->assertManagementTransaction();
        $this->guardManager($manager, true);
        abort_unless($this->canManageLoadedIdentity($manager, $identity, true), 404);
        $this->guardExecutionAccount($executionAccount, true);
        $this->guardDelegatedScope($manager, $executionAccount, $data, true);
        $before = $this->present($identity);
        $identity->fill(Arr::only($data, [
            'name', 'description', 'abilities', 'allowed_work_types', 'allowed_site_ids',
            'allowed_fields', 'require_signature', 'rate_limit_per_minute', 'expires_at',
        ]));
        $identity->forceFill(['configuration_version' => (int) $identity->configuration_version + 1])->save();
        AuditLogger::logOrFail('it.api.identity.updated', $identity, [
            'application_scope' => 'single_installation', 'actor_id' => $manager->id,
            'before' => $before, 'after' => $this->present($identity),
        ]);

        return $identity;
    }

    /** Caller owns the ordered users, identity and current authorization locks. */
    public function rotate(ItServiceIdentity $identity, User $manager, User $executionAccount): array
    {
        $this->assertManagementTransaction();
        $this->guardManager($manager, true);
        abort_unless($this->canManageLoadedIdentity($manager, $identity, true), 404);
        $this->guardExecutionAccount($executionAccount, true);
        $this->guardDelegatedScope($manager, $executionAccount, $identity->only(['abilities', 'allowed_site_ids']), true);
        abort_unless($identity->isActive(), 409, 'Only an active identity can be rotated.');
        $secret = Str::random(64);
        $identity->forceFill([
            'token_hash' => hash('sha256', $secret), 'last_rotated_at' => now(),
            'configuration_version' => (int) $identity->configuration_version + 1,
        ])->save();
        AuditLogger::logOrFail('it.api.identity.rotated', $identity, [
            'application_scope' => 'single_installation', 'actor_id' => $manager->id,
            'configuration_version' => $identity->configuration_version,
        ]);

        return ['identity_id' => $identity->id, 'name' => $identity->name, 'token' => "ofi_{$identity->public_id}_{$secret}"];
    }

    /** Safe register/review metadata; no token or hash. */
    public function present(ItServiceIdentity $identity): array
    {
        $identity->loadMissing(['actor:id,name', 'creator:id,name']);

        return [
            'id' => $identity->id, 'public_id' => $identity->public_id,
            'name' => $identity->name, 'description' => $identity->description,
            'actor' => $identity->actor?->only(['id', 'name']),
            'creator' => $identity->creator?->only(['id', 'name']),
            'abilities' => $identity->abilities ?? [], 'allowed_work_types' => $identity->allowed_work_types ?? [],
            'allowed_site_ids' => $identity->allowed_site_ids ?? [],
            'allowed_fields' => $identity->allowed_fields ?? ['create' => [], 'read' => [], 'update' => []],
            'require_signature' => $identity->require_signature,
            'rate_limit_per_minute' => $identity->rate_limit_per_minute,
            'configuration_version' => (int) $identity->configuration_version,
            'last_rotated_at' => $identity->last_rotated_at?->toIso8601String(),
            'expires_at' => $identity->expires_at?->toIso8601String(),
            'revoked_at' => $identity->revoked_at?->toIso8601String(),
            'last_used_at' => $identity->last_used_at?->toIso8601String(),
            'created_at' => $identity->created_at?->toIso8601String(), 'is_active' => $identity->isActive(),
        ];
    }

    private function assertManagementTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Credential mutation requires its versioned management command.');
        }
    }
}
