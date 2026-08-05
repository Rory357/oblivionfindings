<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSecretReference;
use App\Models\Integration\IntegrationSiteSecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

final class IntegrationSecretMaterialService
{
    public function __construct(private readonly SecretManagerLeaseIssuer $issuer) {}

    public function application(
        IntegrationProviderConnection $connection,
        string $purpose,
        string $field,
    ): string {
        if ($connection->status === IntegrationProviderConnection::STATUS_DISABLED
            || $connection->requires_credential_replacement) {
            throw new RuntimeException('Provider secret is unavailable.');
        }

        $reference = $connection->secretReferences()->where('purpose', $purpose)->first();
        if ($reference?->status === IntegrationSecretReference::STATUS_ACTIVE) {
            return $this->leased($reference, null, $field);
        }
        if ($reference !== null && $reference->status !== IntegrationSecretReference::STATUS_ROLLED_BACK) {
            throw new RuntimeException('Provider secret is unavailable.');
        }

        return $this->legacyApplication($connection, $purpose, $field);
    }

    public function site(IntegrationSiteSecret $siteSecret, string $field): string
    {
        if (! $siteSecret->is_enabled) {
            throw new RuntimeException('Site provider secret is unavailable.');
        }

        $reference = $siteSecret->secretReferences()
            ->where('purpose', IntegrationSecretManager::PURPOSE_PRIMARY)
            ->first();
        if ($reference?->status === IntegrationSecretReference::STATUS_ACTIVE) {
            return $this->leased($reference, (int) $siteSecret->site_id, $field);
        }
        if ($reference !== null && $reference->status !== IntegrationSecretReference::STATUS_ROLLED_BACK) {
            throw new RuntimeException('Site provider secret is unavailable.');
        }

        try {
            $value = Crypt::decryptString((string) $siteSecret->secret_encrypted);
        } catch (Throwable) {
            throw new RuntimeException('Site provider secret is unavailable.');
        }

        return $this->validated($value, $field);
    }

    private function leased(
        IntegrationSecretReference $reference,
        ?int $siteId,
        string $field,
    ): string {
        $lease = null;
        $material = [];
        $value = null;
        $failed = false;

        try {
            $lease = $this->issuer->issue(new SecretLeaseRequest(
                referenceUuid: $reference->reference_uuid,
                siteId: $siteId,
                provider: $reference->provider,
                purpose: $reference->purpose,
                capabilities: ['provider:'.$reference->purpose],
                externalReference: (string) $reference->secret_manager_reference,
                expiresAt: CarbonImmutable::now('UTC')->addSeconds(60),
                secretVersion: (int) $reference->secret_manager_version,
            ));
            $material = $lease->material();
            $value = $this->validated($material[$field] ?? null, $field);
        } catch (Throwable) {
            $failed = true;
        } finally {
            $this->destroyMaterial($material);
            if ($lease !== null) {
                try {
                    $this->issuer->revoke($lease->leaseId);
                } catch (Throwable) {
                    $failed = true;
                }
            }
        }

        if ($failed || $value === null) {
            throw new RuntimeException('Provider secret is unavailable.');
        }

        return $value;
    }

    private function legacyApplication(
        IntegrationProviderConnection $connection,
        string $purpose,
        string $field,
    ): string {
        try {
            $encrypted = $purpose === IntegrationSecretManager::PURPOSE_WEBHOOK
                ? data_get($connection->config, 'webhook_secret_encrypted')
                : $connection->secret_encrypted;
            $value = Crypt::decryptString((string) $encrypted);
        } catch (Throwable) {
            throw new RuntimeException('Provider secret is unavailable.');
        }

        return $this->validated($value, $field);
    }

    private function validated(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 4096
            || preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/', $field) !== 1) {
            throw new RuntimeException('Provider secret is unavailable.');
        }

        return $value;
    }

    /** @param array<string, scalar|null> $material */
    private function destroyMaterial(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
