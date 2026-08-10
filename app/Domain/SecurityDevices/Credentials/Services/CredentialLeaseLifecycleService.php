<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseAuditEvent;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class CredentialLeaseLifecycleService
{
    public function __construct(
        private readonly SecretManagerLeaseIssuer $issuer,
        private readonly CredentialReferenceRules $rules,
    ) {}

    /** @param list<string> $capabilities */
    public function register(
        CredentialReference $reference,
        CredentialLease $lease,
        array $capabilities,
    ): CredentialLeaseGrant {
        return CredentialLeaseGrant::query()->create([
            'credential_reference_id' => $reference->id,
            'reference_version' => $reference->version,
            'site_id' => $reference->site_id,
            'lease_id' => $lease->leaseId,
            'lease_fingerprint' => $this->rules->fingerprint($lease->leaseId),
            'capabilities' => $capabilities,
            'status' => CredentialLeaseGrant::STATUS_ISSUED,
            'issued_at' => CarbonImmutable::now('UTC'),
            'expires_at' => $lease->expiresAt,
        ]);
    }

    public function release(CredentialLease $lease): void
    {
        $grant = CredentialLeaseGrant::query()
            ->where('lease_fingerprint', $this->rules->fingerprint($lease->leaseId))
            ->first();
        if ($grant === null) {
            $this->revokeUnregistered($lease->leaseId);

            return;
        }

        $this->terminate($grant, 'released');
    }

    /** @return array{contained: int, pending: int} */
    public function containReference(CredentialReference $reference, string $reason): array
    {
        $contained = 0;
        $pending = 0;
        $this->activeGrants($reference)
            ->each(function (CredentialLeaseGrant $grant) use ($reason, &$contained, &$pending): void {
                $ended = $this->terminate($grant, $reason);
                $ended ? $contained++ : $pending++;
            });

        return ['contained' => $contained, 'pending' => $pending];
    }

    /** @return array{expired: int, retried: int, pending: int} */
    public function reconcile(): array
    {
        $now = CarbonImmutable::now('UTC');
        $expired = 0;
        CredentialLeaseGrant::query()
            ->whereIn('status', [
                CredentialLeaseGrant::STATUS_ISSUED,
                CredentialLeaseGrant::STATUS_REVOKE_PENDING,
            ])
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($grants) use (&$expired): void {
                foreach ($grants as $grant) {
                    if ($this->terminate($grant, 'expired')) {
                        $expired++;
                    }
                }
            });

        $retried = 0;
        $pending = 0;
        CredentialLeaseGrant::query()
            ->where('status', CredentialLeaseGrant::STATUS_REVOKE_PENDING)
            ->where('expires_at', '>', $now)
            ->where(fn ($query) => $query
                ->whereNull('last_revoke_attempt_at')
                ->orWhere('last_revoke_attempt_at', '<=', $now->subSeconds(15)))
            ->orderBy('id')
            ->chunkById(100, function ($grants) use (&$retried, &$pending): void {
                foreach ($grants as $grant) {
                    $this->terminate($grant, 'containment_retry') ? $retried++ : $pending++;
                }
            });

        return ['expired' => $expired, 'retried' => $retried, 'pending' => $pending];
    }

    /** @return Collection<int, CredentialLeaseGrant> */
    private function activeGrants(CredentialReference $reference): Collection
    {
        return CredentialLeaseGrant::query()
            ->where('credential_reference_id', $reference->id)
            ->whereIn('status', [
                CredentialLeaseGrant::STATUS_ISSUED,
                CredentialLeaseGrant::STATUS_REVOKE_PENDING,
            ])
            ->orderBy('id')
            ->get();
    }

    private function terminate(CredentialLeaseGrant $grant, string $reason): bool
    {
        $grant = $grant->fresh();
        if (! $grant || ! in_array($grant->status, [
            CredentialLeaseGrant::STATUS_ISSUED,
            CredentialLeaseGrant::STATUS_REVOKE_PENDING,
        ], true)) {
            return true;
        }
        $leaseId = $grant->lease_id;
        if (! is_string($leaseId) || $leaseId === '') {
            throw new RuntimeException('Credential lease containment evidence is incomplete.');
        }

        $now = CarbonImmutable::now('UTC');
        $expired = $grant->expires_at->lte($now);
        $revoked = false;
        try {
            $this->issuer->revoke($leaseId);
            $revoked = true;
        } catch (Throwable) {
            // Before expiry the encrypted lease identifier is retained for retry.
            // At authoritative expiry it is erased even when the provider is unavailable.
        }

        if (! $revoked && ! $expired) {
            $grant->forceFill([
                'status' => CredentialLeaseGrant::STATUS_REVOKE_PENDING,
                'revoke_attempts' => $grant->revoke_attempts + 1,
                'last_failure_code' => 'provider_revoke_failed',
                'last_revoke_attempt_at' => $now,
            ])->save();
            $this->audit($grant, 'revoke_deferred', $reason, false);
            $this->destroyString($leaseId);

            return false;
        }

        $status = $expired
            ? CredentialLeaseGrant::STATUS_EXPIRED
            : ($reason === 'released'
                ? CredentialLeaseGrant::STATUS_RELEASED
                : CredentialLeaseGrant::STATUS_CONTAINED);
        $grant->forceFill([
            'lease_id' => null,
            'status' => $status,
            'revoke_attempts' => $grant->revoke_attempts + 1,
            'last_failure_code' => $revoked ? null : 'expired_provider_unavailable',
            'last_revoke_attempt_at' => $now,
            'ended_at' => $now,
        ])->save();
        $this->audit($grant, $status, $reason, $revoked);
        $this->destroyString($leaseId);

        return true;
    }

    private function revokeUnregistered(#[\SensitiveParameter] string $leaseId): void
    {
        try {
            $this->issuer->revoke($leaseId);
        } catch (Throwable) {
            // Fixture and compatibility providers remain fail-safe and short-lived.
        } finally {
            $this->destroyString($leaseId);
        }
    }

    private function audit(
        CredentialLeaseGrant $grant,
        string $action,
        string $reason,
        bool $providerRevoked,
    ): void {
        CredentialLeaseAuditEvent::query()->create([
            'credential_reference_id' => $grant->credential_reference_id,
            'site_id' => $grant->site_id,
            'action' => $action,
            'reference_fingerprint' => $this->rules->fingerprint((string) $grant->reference?->reference_key),
            'lease_fingerprint' => $grant->lease_fingerprint,
            'capabilities' => $grant->capabilities,
            'safe_context' => [
                'grant_uuid' => $grant->grant_uuid,
                'reference_version' => $grant->reference_version,
                'reason' => substr(preg_replace('/[^a-z0-9_]+/', '_', strtolower($reason)) ?? '', 0, 40),
                'provider_revoke_confirmed' => $providerRevoked,
                'revoke_attempts' => $grant->revoke_attempts,
            ],
            'expires_at' => $grant->expires_at,
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function destroyString(#[\SensitiveParameter] string &$value): void
    {
        if ($value !== '') {
            sodium_memzero($value);
        }
    }
}
