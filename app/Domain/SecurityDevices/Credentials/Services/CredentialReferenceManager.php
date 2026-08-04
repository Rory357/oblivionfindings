<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReferenceAuditEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CredentialReferenceManager
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CredentialReferenceRules $rules,
        private readonly SecretManagerLeaseIssuer $issuer,
        private readonly CredentialLeaseLifecycleService $leases,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function register(User $actor, #[\SensitiveParameter] array $attributes): CredentialReference
    {
        $siteId = (int) ($attributes['site_id'] ?? 0);
        $this->authorise($actor, $siteId);
        $referenceKey = $this->rules->referenceKey((string) ($attributes['reference_key'] ?? ''));
        $provider = $this->rules->provider((string) ($attributes['provider'] ?? ''));
        $purpose = $this->rules->purpose((string) ($attributes['purpose'] ?? ''));
        $capabilities = $this->rules->capabilities((array) ($attributes['capabilities'] ?? []));
        $externalReference = $this->rules->externalReference((string) ($attributes['secret_manager_reference'] ?? ''));

        return DB::transaction(function () use ($actor, $siteId, $referenceKey, $provider, $purpose, $capabilities, $externalReference): CredentialReference {
            $reference = CredentialReference::query()->create([
                'reference_key' => $referenceKey,
                'site_id' => $siteId,
                'provider' => $provider,
                'purpose' => $purpose,
                'capabilities' => $capabilities,
                'secret_manager_reference' => $externalReference,
                'secret_manager_reference_hash' => $this->rules->fingerprint($externalReference),
                'status' => CredentialReferenceStatus::Suspended,
                'rotation_status' => CredentialRotationStatus::Due,
                'test_status' => CredentialTestStatus::Untested,
                'version' => 1,
                'created_by_user_id' => $actor->id,
            ]);
            $this->audit($reference, $actor, 'registered', [
                'provider' => $provider,
                'purpose' => $purpose,
                'capabilities' => $capabilities,
                'status' => CredentialReferenceStatus::Suspended->value,
            ]);

            return $reference->fresh();
        });
    }

    public function rotate(
        User $actor,
        CredentialReference $reference,
        #[\SensitiveParameter] string $externalReference,
    ): CredentialReference {
        $this->authoriseReference($actor, $reference);
        $externalReference = $this->rules->externalReference($externalReference);

        $updated = DB::transaction(function () use ($actor, $reference, $externalReference): CredentialReference {
            $locked = CredentialReference::query()->lockForUpdate()->findOrFail($reference->id);
            if ($locked->status === CredentialReferenceStatus::Revoked) {
                throw new RuntimeException('A revoked credential reference cannot be rotated.');
            }
            $locked->forceFill([
                'secret_manager_reference' => $externalReference,
                'secret_manager_reference_hash' => $this->rules->fingerprint($externalReference),
                'status' => CredentialReferenceStatus::Suspended,
                'rotation_status' => CredentialRotationStatus::Due,
                'test_status' => CredentialTestStatus::Untested,
                'version' => $locked->version + 1,
                'last_rotated_by_user_id' => $actor->id,
                'last_rotated_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->audit($locked, $actor, 'rotated', [
                'status' => CredentialReferenceStatus::Suspended->value,
                'test_status' => CredentialTestStatus::Untested->value,
            ]);

            return $locked->fresh();
        });
        $this->leases->containReference($updated, 'credential_rotation');

        return $updated->fresh();
    }

    public function test(User $actor, CredentialReference $reference): CredentialReference
    {
        $this->authoriseReference($actor, $reference);
        $reference = $reference->fresh();
        if ($reference->status === CredentialReferenceStatus::Revoked) {
            throw new RuntimeException('A revoked credential reference cannot be tested.');
        }
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds(
            max(15, min(300, (int) config('monitoring.credentials.lease_ttl_seconds', 60))),
        );
        $lease = null;
        try {
            $lease = $this->issuer->issue(new SecretLeaseRequest(
                referenceUuid: $reference->reference_uuid,
                siteId: (int) $reference->site_id,
                provider: $reference->provider,
                purpose: $reference->purpose,
                capabilities: $reference->capabilities,
                externalReference: (string) $reference->secret_manager_reference,
                expiresAt: $expiresAt,
            ));
            $lease->material();
            $passed = true;
        } catch (Throwable) {
            $passed = false;
        } finally {
            if ($lease !== null) {
                try {
                    $this->issuer->revoke($lease->leaseId);
                } catch (Throwable) {
                    $passed = false;
                }
            }
        }

        $updated = DB::transaction(function () use ($actor, $reference, $passed): CredentialReference {
            $locked = CredentialReference::query()->lockForUpdate()->findOrFail($reference->id);
            if ($locked->version !== $reference->version || $locked->status === CredentialReferenceStatus::Revoked) {
                throw new RuntimeException('The credential reference changed while it was being tested.');
            }
            $locked->forceFill([
                'status' => $passed ? CredentialReferenceStatus::Active : CredentialReferenceStatus::Suspended,
                'rotation_status' => $passed ? CredentialRotationStatus::Current : CredentialRotationStatus::Failed,
                'test_status' => $passed ? CredentialTestStatus::Passed : CredentialTestStatus::Failed,
                'version' => $locked->version + 1,
                'last_tested_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->audit($locked, $actor, $passed ? 'test_passed' : 'test_failed', [
                'status' => $locked->status->value,
                'test_status' => $locked->test_status->value,
            ]);

            return $locked->fresh();
        });
        if (! $passed) {
            throw new RuntimeException('The credential reference test failed. It remains suspended.');
        }

        return $updated;
    }

    public function revoke(User $actor, CredentialReference $reference): CredentialReference
    {
        $this->authoriseReference($actor, $reference);

        $updated = DB::transaction(function () use ($actor, $reference): CredentialReference {
            $locked = CredentialReference::query()->lockForUpdate()->findOrFail($reference->id);
            if ($locked->status !== CredentialReferenceStatus::Revoked) {
                $locked->forceFill([
                    'status' => CredentialReferenceStatus::Revoked,
                    'version' => $locked->version + 1,
                    'revoked_at' => CarbonImmutable::now('UTC'),
                ])->save();
                $this->audit($locked, $actor, 'revoked', [
                    'status' => CredentialReferenceStatus::Revoked->value,
                ]);
            }

            return $locked->fresh();
        });
        $this->leases->containReference($updated, 'credential_revocation');

        return $updated->fresh();
    }

    private function authorise(User $actor, int $siteId): void
    {
        abort_unless($actor->canDo('securityDevices.commands.admin'), 403);
        $this->access->assertCanViewSite($actor, $siteId);
    }

    private function authoriseReference(User $actor, CredentialReference $reference): void
    {
        $this->authorise($actor, (int) $reference->site_id);
    }

    /** @param array<string, mixed> $safeContext */
    private function audit(CredentialReference $reference, User $actor, string $action, array $safeContext): void
    {
        CredentialReferenceAuditEvent::query()->create([
            'credential_reference_id' => $reference->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'version' => $reference->version,
            'safe_context' => $safeContext,
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
