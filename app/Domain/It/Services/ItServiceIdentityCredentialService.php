<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItServiceIdentity;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ItServiceIdentityCredentialService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{identity: ItServiceIdentity, secret: string, token: string}
     */
    public function create(User $actor, int $tenantId, array $data): array
    {
        $this->guardManager($actor, $tenantId);
        $this->guardExecutionAccount($tenantId, (int) $data['actor_user_id']);

        return DB::transaction(function () use ($actor, $tenantId, $data): array {
            $publicId = Str::lower(Str::random(20));
            $secret = Str::random(64);
            $token = "ofi_{$publicId}_{$secret}";
            $identity = ItServiceIdentity::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => (int) $data['actor_user_id'],
                'created_by_user_id' => $actor->id,
                'public_id' => $publicId,
                'token_hash' => hash('sha256', $secret),
                ...Arr::only($data, [
                    'name', 'description', 'abilities', 'allowed_work_types',
                    'allowed_site_ids', 'allowed_fields', 'require_signature',
                    'rate_limit_per_minute', 'expires_at',
                ]),
            ]);

            AuditLogger::logOrFail('it.api.identity.created', $identity, [
                'organization_id' => $tenantId,
                'actor_id' => $actor->id,
                'abilities' => $identity->abilities,
                'allowed_work_types' => $identity->allowed_work_types,
                'allowed_site_ids' => $identity->allowed_site_ids,
                'require_signature' => $identity->require_signature,
            ]);

            return ['identity' => $identity->fresh('actor'), 'secret' => $secret, 'token' => $token];
        });
    }

    public function revoke(ItServiceIdentity $identity, User $actor, int $tenantId): ItServiceIdentity
    {
        $this->guardManager($actor, $tenantId);

        return DB::transaction(function () use ($identity, $actor, $tenantId): ItServiceIdentity {
            $locked = ItServiceIdentity::query()
                ->forTenant($tenantId)
                ->whereKey($identity->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->id,
                ])->save();
                AuditLogger::logOrFail('it.api.identity.revoked', $locked, [
                    'organization_id' => $tenantId,
                    'actor_id' => $actor->id,
                ]);
            }

            return $locked->refresh();
        });
    }

    private function guardManager(User $actor, int $tenantId): void
    {
        if (! $actor->canDo('it.manage') || (int) $actor->organization_id !== $tenantId) {
            throw new DomainException('You are not allowed to manage IT service identities.');
        }
    }

    private function guardExecutionAccount(int $tenantId, int $userId): void
    {
        if (! ItStaffDirectory::agents($tenantId)->contains(fn (User $user) => $user->id === $userId)) {
            throw new DomainException('The execution account must be an IT agent in this organisation.');
        }
    }
}
