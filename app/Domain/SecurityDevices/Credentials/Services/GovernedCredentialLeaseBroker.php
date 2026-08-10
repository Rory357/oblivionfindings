<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseAuditEvent;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class GovernedCredentialLeaseBroker implements CredentialLeaseProvider
{
    public function __construct(
        private readonly CredentialReferenceRules $rules,
        private readonly SecretManagerLeaseIssuer $issuer,
        private readonly CredentialLeaseLifecycleService $lifecycle,
    ) {}

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $catalogueReference = null;
        $requestedCapabilities = [];
        try {
            if ($siteId < 1) {
                throw new RuntimeException('Invalid Site scope.');
            }
            $reference = $this->rules->referenceKey($reference);
            $requestedCapabilities = $this->rules->capabilities($capabilities);
            $catalogueReference = CredentialReference::query()
                ->where('reference_key', $reference)
                ->where('site_id', $siteId)
                ->first();
            if (! $catalogueReference
                || $catalogueReference->status !== CredentialReferenceStatus::Active
                || in_array($catalogueReference->rotation_status, [
                    CredentialRotationStatus::Overdue,
                    CredentialRotationStatus::Failed,
                ], true)
                || array_diff($requestedCapabilities, $catalogueReference->capabilities ?? []) !== []) {
                throw new RuntimeException('Credential reference is unavailable.');
            }

            $now = CarbonImmutable::now('UTC');
            $ttl = max(15, min(300, (int) config('monitoring.credentials.lease_ttl_seconds', 60)));
            $requestedExpiry = $now->addSeconds($ttl);
            $lease = $this->issuer->issue(new SecretLeaseRequest(
                referenceUuid: $catalogueReference->reference_uuid,
                siteId: $siteId,
                provider: $catalogueReference->provider,
                purpose: $catalogueReference->purpose,
                capabilities: $requestedCapabilities,
                externalReference: (string) $catalogueReference->secret_manager_reference,
                expiresAt: $requestedExpiry,
            ));
            if ($lease->expiresAt->lessThanOrEqualTo($now)
                || $lease->expiresAt->greaterThan($requestedExpiry)) {
                $this->revokeSafely($lease->leaseId);
                throw new RuntimeException('Credential lease expiry is invalid.');
            }

            DB::transaction(function () use ($catalogueReference, $siteId, $reference, $requestedCapabilities, $lease): void {
                $stillCurrent = CredentialReference::query()
                    ->whereKey($catalogueReference->id)
                    ->where('version', $catalogueReference->version)
                    ->where('status', CredentialReferenceStatus::Active->value)
                    ->lockForUpdate()
                    ->exists();
                if (! $stillCurrent) {
                    throw new RuntimeException('Credential reference changed during lease issue.');
                }
                CredentialLeaseAuditEvent::query()->create([
                    'credential_reference_id' => $catalogueReference->id,
                    'site_id' => $siteId,
                    'action' => 'issued',
                    'reference_fingerprint' => $this->rules->fingerprint($reference),
                    'lease_fingerprint' => $this->rules->fingerprint($lease->leaseId),
                    'capabilities' => $requestedCapabilities,
                    'safe_context' => [
                        'provider' => $catalogueReference->provider,
                        'purpose' => $catalogueReference->purpose,
                        'reference_uuid' => $catalogueReference->reference_uuid,
                        'version' => $catalogueReference->version,
                    ],
                    'expires_at' => $lease->expiresAt,
                    'occurred_at' => CarbonImmutable::now('UTC'),
                ]);
                $this->lifecycle->register($catalogueReference, $lease, $requestedCapabilities);
            });

            return $lease;
        } catch (Throwable) {
            if (isset($lease) && $lease instanceof CredentialLease) {
                $this->revokeSafely($lease->leaseId);
            }
            $this->recordDenial($catalogueReference, $siteId, $reference, $requestedCapabilities);

            throw new RuntimeException('Credential lease is unavailable.');
        }
    }

    /** @param list<string> $capabilities */
    private function recordDenial(?CredentialReference $reference, int $siteId, string $referenceKey, array $capabilities): void
    {
        try {
            CredentialLeaseAuditEvent::query()->create([
                'credential_reference_id' => $reference?->id,
                'site_id' => $reference?->site_id,
                'action' => 'denied',
                'reference_fingerprint' => $this->rules->fingerprint($referenceKey),
                'lease_fingerprint' => null,
                'capabilities' => $capabilities,
                'safe_context' => [
                    'requested_site_id' => $siteId > 0 ? $siteId : null,
                    'reason' => 'lease_unavailable',
                ],
                'expires_at' => null,
                'occurred_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (Throwable) {
            // A failed audit write never makes a denied lease available.
        }
    }

    private function revokeSafely(#[\SensitiveParameter] string $leaseId): void
    {
        try {
            $this->issuer->revoke($leaseId);
        } catch (Throwable) {
            // The original request remains failed closed; external expiry is authoritative.
        }
    }
}
