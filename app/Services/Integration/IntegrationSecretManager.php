<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSecretReference;
use App\Models\Integration\IntegrationSiteSecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class IntegrationSecretManager
{
    public const PURPOSE_PRIMARY = 'primary';

    public const PURPOSE_WEBHOOK = 'webhook';

    private const PROVIDER_FIELDS = [
        'unifi' => [self::PURPOSE_PRIMARY => 'api_key'],
        'milesight' => [
            self::PURPOSE_PRIMARY => 'client_secret',
            self::PURPOSE_WEBHOOK => 'webhook_secret',
        ],
    ];

    public function __construct(
        private readonly SecretManagerSecretStore $store,
        private readonly SecretManagerLeaseIssuer $issuer,
    ) {}

    /** @param array<string, scalar|null> $material */
    public function storeApplication(
        IntegrationProviderConnection $connection,
        string $purpose,
        #[\SensitiveParameter] array $material,
    ): IntegrationSecretReference {
        $provider = $this->provider($connection->provider);
        $field = $this->applicationField($provider, $purpose);
        $this->assertMaterial($material, $field);

        return $this->storeReference(
            provider: $provider,
            purpose: $purpose,
            reference: $this->applicationReference($provider, $purpose),
            material: $material,
            providerConnectionId: (int) $connection->id,
        );
    }

    /** @param array<string, scalar|null> $material */
    public function storeSite(
        IntegrationSiteSecret $siteSecret,
        #[\SensitiveParameter] array $material,
    ): IntegrationSecretReference {
        $provider = $this->provider($siteSecret->provider);
        $field = $provider === 'unifi' ? 'api_key' : 'client_secret';
        $this->assertMaterial($material, $field);

        return $this->storeReference(
            provider: $provider,
            purpose: self::PURPOSE_PRIMARY,
            reference: $this->siteReference($siteSecret),
            material: $material,
            siteSecretId: (int) $siteSecret->id,
            siteId: (int) $siteSecret->site_id,
        );
    }

    public function applicationConfigured(
        IntegrationProviderConnection $connection,
        string $purpose,
    ): bool {
        $this->applicationField($connection->provider, $purpose);

        return $connection->secretReferences()
            ->where('purpose', $purpose)
            ->active()
            ->exists();
    }

    public function revokeApplication(
        IntegrationProviderConnection $connection,
        string $purpose,
    ): bool {
        $this->applicationField($connection->provider, $purpose);
        $reference = $connection->secretReferences()->where('purpose', $purpose)->first();
        if ($reference === null) {
            return true;
        }

        if ($reference->status !== IntegrationSecretReference::STATUS_REVOKED) {
            $reference->forceFill([
                'status' => IntegrationSecretReference::STATUS_REVOKED,
                'revoked_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return $this->cleanupReference($reference);
        }

        return $reference->cleanup_pending_at === null
            || $this->cleanupReference($reference);
    }

    public function revokeSite(IntegrationSiteSecret $siteSecret): bool
    {
        $reference = $siteSecret->secretReferences()->where('purpose', self::PURPOSE_PRIMARY)->first();
        if ($reference === null) {
            return true;
        }

        if ($reference->status !== IntegrationSecretReference::STATUS_REVOKED) {
            $reference->forceFill([
                'status' => IntegrationSecretReference::STATUS_REVOKED,
                'revoked_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return $this->cleanupReference($reference);
        }

        return $reference->cleanup_pending_at === null
            || $this->cleanupReference($reference);
    }

    /** @param list<string> $purposes */
    public function deleteApplicationConnection(
        IntegrationProviderConnection $connection,
        array $purposes,
    ): void {
        $completedReferenceIds = [];
        $pendingReferenceIds = [];
        foreach (array_values(array_unique($purposes)) as $purpose) {
            $reference = $connection->secretReferences()->where('purpose', $purpose)->first();
            if ($reference === null) {
                $this->revokeApplication($connection, $purpose);

                continue;
            }

            if ($this->revokeApplication($connection, $purpose)) {
                $completedReferenceIds[] = (int) $reference->id;
            } else {
                $pendingReferenceIds[] = (int) $reference->id;
            }
        }

        DB::transaction(function () use ($connection, $completedReferenceIds, $pendingReferenceIds): void {
            if ($completedReferenceIds !== []) {
                IntegrationSecretReference::query()
                    ->whereKey($completedReferenceIds)
                    ->where('provider_connection_id', $connection->id)
                    ->delete();
            }
            if ($pendingReferenceIds !== []) {
                IntegrationSecretReference::query()
                    ->whereKey($pendingReferenceIds)
                    ->where('provider_connection_id', $connection->id)
                    ->where('status', IntegrationSecretReference::STATUS_REVOKED)
                    ->whereNotNull('cleanup_pending_at')
                    ->update(['provider_connection_id' => null]);
            }

            IntegrationProviderConnection::query()
                ->lockForUpdate()
                ->findOrFail($connection->id)
                ->delete();
        });
    }

    /** @return array{processed: int, cleaned: int, remaining: int} */
    public function retryPendingCleanup(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $references = IntegrationSecretReference::query()
            ->whereNotNull('cleanup_pending_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $cleaned = 0;

        foreach ($references as $reference) {
            try {
                $cleaned += $this->cleanupReference($reference) ? 1 : 0;
            } catch (Throwable) {
                // The value-free pointer remains durable for the next retry.
            }
        }

        return [
            'processed' => $references->count(),
            'cleaned' => $cleaned,
            'remaining' => IntegrationSecretReference::query()->whereNotNull('cleanup_pending_at')->count(),
        ];
    }

    public function rollbackApplication(
        IntegrationProviderConnection $connection,
        string $purpose,
    ): IntegrationSecretReference {
        $this->assertLegacyAvailable($connection, $purpose);
        $reference = $connection->secretReferences()
            ->where('purpose', $purpose)
            ->where('status', IntegrationSecretReference::STATUS_ACTIVE)
            ->firstOrFail();

        $reference->forceFill([
            'status' => IntegrationSecretReference::STATUS_ROLLED_BACK,
            'rolled_back_at' => CarbonImmutable::now('UTC'),
        ])->save();

        try {
            $this->store->softDelete(new SecretVersionRequest(
                (string) $reference->secret_manager_reference,
                (int) $reference->secret_manager_version,
            ));
        } catch (Throwable) {
            // The local rollback is authoritative. The active runtime now uses
            // the preserved legacy value and external cleanup can be retried.
        }

        return $reference->fresh();
    }

    public function restoreApplication(
        IntegrationProviderConnection $connection,
        string $purpose,
    ): IntegrationSecretReference {
        $provider = $this->provider($connection->provider);
        $field = $this->applicationField($provider, $purpose);
        $this->assertLegacyAvailable($connection, $purpose);
        $reference = $connection->secretReferences()
            ->where('purpose', $purpose)
            ->where('status', IntegrationSecretReference::STATUS_ROLLED_BACK)
            ->firstOrFail();
        $encrypted = $purpose === self::PURPOSE_WEBHOOK
            ? data_get($connection->config, 'webhook_secret_encrypted')
            : $connection->secret_encrypted;
        try {
            $legacy = Crypt::decryptString((string) $encrypted);
        } catch (Throwable) {
            throw new RuntimeException('A verified legacy provider secret is unavailable for restore.');
        }

        $stored = new StoredSecretVersion(
            opaqueReference: (string) $reference->secret_manager_reference,
            version: (int) $reference->secret_manager_version,
            fingerprint: (string) $reference->secret_manager_fingerprint,
        );
        try {
            $this->store->restore(new SecretVersionRequest($stored->opaqueReference, $stored->version));
            $this->verifyStoredMaterial($stored, $provider, $purpose, null, [$field => $legacy]);
        } catch (Throwable) {
            try {
                $this->store->softDelete(new SecretVersionRequest($stored->opaqueReference, $stored->version));
            } catch (Throwable) {
                // The local reference remains rolled back and cannot use the
                // unverified external version.
            }

            throw new RuntimeException('Provider secret restore failed.');
        } finally {
            $this->destroyString($legacy);
        }

        $reference->forceFill([
            'status' => IntegrationSecretReference::STATUS_ACTIVE,
            'rolled_back_at' => null,
            'revoked_at' => null,
        ])->save();

        return $reference->fresh();
    }

    public function backfillApplication(IntegrationProviderConnection $connection): int
    {
        $provider = $this->provider($connection->provider);
        $migrated = 0;

        if (! $this->applicationConfigured($connection, self::PURPOSE_PRIMARY)
            && filled($connection->secret_encrypted)) {
            $field = $this->applicationField($provider, self::PURPOSE_PRIMARY);
            $material = Crypt::decryptString((string) $connection->secret_encrypted);
            try {
                $this->storeApplication($connection, self::PURPOSE_PRIMARY, [$field => $material]);
            } finally {
                $this->destroyString($material);
            }
            $migrated++;
        }

        $config = is_array($connection->config) ? $connection->config : [];
        if ($provider === 'milesight'
            && ! $this->applicationConfigured($connection, self::PURPOSE_WEBHOOK)
            && filled($config['webhook_secret_encrypted'] ?? null)) {
            $material = Crypt::decryptString((string) $config['webhook_secret_encrypted']);
            try {
                $this->storeApplication($connection, self::PURPOSE_WEBHOOK, ['webhook_secret' => $material]);
            } finally {
                $this->destroyString($material);
            }
            $migrated++;
        }

        return $migrated;
    }

    public function backfillSite(IntegrationSiteSecret $siteSecret): bool
    {
        $provider = $this->provider($siteSecret->provider);
        if ($siteSecret->secretReferences()->where('purpose', self::PURPOSE_PRIMARY)->active()->exists()
            || blank($siteSecret->secret_encrypted)) {
            return false;
        }

        $material = Crypt::decryptString((string) $siteSecret->secret_encrypted);
        $field = $provider === 'unifi' ? 'api_key' : 'client_secret';
        try {
            $this->storeSite($siteSecret, [$field => $material]);
        } finally {
            $this->destroyString($material);
        }

        return true;
    }

    public function finalizeApplication(IntegrationProviderConnection $connection): void
    {
        if (! $this->applicationConfigured($connection, self::PURPOSE_PRIMARY)) {
            throw new RuntimeException('Provider secret cutover is not active.');
        }

        $config = is_array($connection->config) ? $connection->config : [];
        if ($connection->provider === 'milesight'
            && filled($config['webhook_secret_encrypted'] ?? null)
            && ! $this->applicationConfigured($connection, self::PURPOSE_WEBHOOK)) {
            throw new RuntimeException('Provider webhook secret cutover is not active.');
        }
        unset($config['webhook_secret_encrypted']);

        $connection->forceFill([
            'secret_encrypted' => null,
            'config' => $config,
        ])->save();
    }

    public function finalizeSite(IntegrationSiteSecret $siteSecret): void
    {
        if (! $siteSecret->secretReferences()->where('purpose', self::PURPOSE_PRIMARY)->active()->exists()) {
            throw new RuntimeException('Site provider secret cutover is not active.');
        }

        $siteSecret->forceFill(['secret_encrypted' => null])->save();
    }

    /** @param array<string, scalar|null> $material */
    private function storeReference(
        string $provider,
        string $purpose,
        #[\SensitiveParameter] string $reference,
        #[\SensitiveParameter] array $material,
        ?int $providerConnectionId = null,
        ?int $siteSecretId = null,
        ?int $siteId = null,
    ): IntegrationSecretReference {
        $query = IntegrationSecretReference::query()->where('purpose', $purpose);
        $providerConnectionId === null
            ? $query->where('site_secret_id', $siteSecretId)
            : $query->where('provider_connection_id', $providerConnectionId);
        $existing = $query->first();
        $expectedVersion = $this->expectedVersion($reference, $existing);

        $stored = null;
        try {
            $stored = $this->store->put(new SecretWriteRequest($reference, $material, $expectedVersion));
            $this->verifyStoredMaterial($stored, $provider, $purpose, $siteId, $material);
        } catch (Throwable) {
            if ($stored instanceof StoredSecretVersion) {
                try {
                    $this->store->softDelete(new SecretVersionRequest($stored->opaqueReference, $stored->version));
                } catch (Throwable) {
                    // No local reference was activated for the failed version.
                }
            }

            throw new RuntimeException('Provider secret cutover failed.');
        }

        try {
            return DB::transaction(function () use (
                $existing,
                $providerConnectionId,
                $siteSecretId,
                $provider,
                $purpose,
                $stored,
            ): IntegrationSecretReference {
                $reference = $existing === null
                    ? new IntegrationSecretReference
                    : IntegrationSecretReference::query()->lockForUpdate()->findOrFail($existing->id);
                $reference->forceFill([
                    'provider_connection_id' => $providerConnectionId,
                    'site_secret_id' => $siteSecretId,
                    'provider' => $provider,
                    'purpose' => $purpose,
                    'secret_manager_reference' => $stored->opaqueReference,
                    'secret_manager_reference_hash' => $stored->fingerprint,
                    'secret_manager_version' => $stored->version,
                    'secret_manager_fingerprint' => $stored->fingerprint,
                    'status' => IntegrationSecretReference::STATUS_ACTIVE,
                    'cutover_at' => CarbonImmutable::now('UTC'),
                    'rolled_back_at' => null,
                    'revoked_at' => null,
                ])->save();

                return $reference->fresh();
            });
        } catch (Throwable) {
            try {
                $this->store->softDelete(new SecretVersionRequest($stored->opaqueReference, $stored->version));
            } catch (Throwable) {
                // The reference was not activated locally and cannot be resolved.
            }

            throw new RuntimeException('Provider secret cutover failed.');
        }
    }

    /** @param array<string, scalar|null> $expected */
    private function verifyStoredMaterial(
        StoredSecretVersion $stored,
        string $provider,
        string $purpose,
        ?int $siteId,
        #[\SensitiveParameter] array $expected,
    ): void {
        $lease = null;
        $material = [];

        try {
            $lease = $this->issuer->issue(new SecretLeaseRequest(
                referenceUuid: 'provider-cutover-'.$stored->fingerprint,
                siteId: $siteId,
                provider: $provider,
                purpose: $purpose,
                capabilities: ['provider:'.$purpose],
                externalReference: $stored->opaqueReference,
                expiresAt: CarbonImmutable::now('UTC')->addSeconds(60),
                secretVersion: $stored->version,
            ));
            $material = $lease->material();
            if (array_keys($material) !== array_keys($expected)) {
                throw new RuntimeException('Provider secret verification failed.');
            }
            foreach ($expected as $field => $value) {
                if (! is_scalar($material[$field] ?? null)
                    || ! hash_equals((string) $value, (string) $material[$field])) {
                    throw new RuntimeException('Provider secret verification failed.');
                }
            }
        } finally {
            $this->destroyMaterial($material);
            if ($lease !== null) {
                $this->issuer->revoke($lease->leaseId);
            }
        }
    }

    private function assertLegacyAvailable(IntegrationProviderConnection $connection, string $purpose): void
    {
        $this->applicationField($connection->provider, $purpose);
        $available = $purpose === self::PURPOSE_PRIMARY
            ? filled($connection->secret_encrypted)
            : filled(data_get($connection->config, 'webhook_secret_encrypted'));
        if (! $available) {
            throw new RuntimeException('A verified legacy provider secret is unavailable for rollback.');
        }
    }

    /** @param array<string, scalar|null> $material */
    private function assertMaterial(array $material, string $field): void
    {
        if (array_keys($material) !== [$field]
            || ! is_string($material[$field])
            || $material[$field] === ''
            || strlen($material[$field]) > 4096) {
            throw new RuntimeException('Provider secret material is invalid.');
        }
    }

    private function applicationField(string $provider, string $purpose): string
    {
        $provider = $this->provider($provider);
        $field = self::PROVIDER_FIELDS[$provider][$purpose] ?? null;
        if ($field === null) {
            throw new RuntimeException('Provider secret purpose is unsupported.');
        }

        return $field;
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (! array_key_exists($provider, self::PROVIDER_FIELDS)) {
            throw new RuntimeException('Provider secret cutover is unsupported.');
        }

        return $provider;
    }

    private function expectedVersion(
        #[\SensitiveParameter] string $reference,
        ?IntegrationSecretReference $existing,
    ): int {
        $expected = (int) ($existing?->secret_manager_version ?? 0);

        try {
            $metadata = $this->store->metadata(new SecretReferenceRequest($reference));

            return max($expected, $metadata->version);
        } catch (Throwable) {
            // A new path has no metadata. An unavailable metadata endpoint is
            // still protected by the write's compare-and-set operation.
            return $expected;
        }
    }

    private function cleanupReference(IntegrationSecretReference $reference): bool
    {
        $attemptedAt = CarbonImmutable::now('UTC');
        $reference->forceFill([
            'cleanup_last_attempt_at' => $attemptedAt,
            'cleanup_attempts' => (int) $reference->cleanup_attempts + 1,
        ])->save();

        try {
            $this->store->softDelete(new SecretVersionRequest(
                (string) $reference->secret_manager_reference,
                (int) $reference->secret_manager_version,
            ));
        } catch (Throwable) {
            $reference->forceFill([
                'cleanup_pending_at' => $reference->cleanup_pending_at ?? $attemptedAt,
            ])->save();

            return false;
        }

        if ($reference->provider_connection_id === null && $reference->site_secret_id === null) {
            $reference->delete();

            return true;
        }

        $reference->forceFill(['cleanup_pending_at' => null])->save();

        return true;
    }

    private function applicationReference(string $provider, string $purpose): string
    {
        $leaf = match ([$provider, $purpose]) {
            ['unifi', self::PURPOSE_PRIMARY] => 'api-key',
            ['milesight', self::PURPOSE_PRIMARY] => 'oauth',
            ['milesight', self::PURPOSE_WEBHOOK] => 'webhook',
            default => throw new RuntimeException('Provider secret purpose is unsupported.'),
        };

        return $this->referencePrefix().'/'.$provider.'/'.$leaf;
    }

    private function siteReference(IntegrationSiteSecret $siteSecret): string
    {
        $capability = strtolower(trim($siteSecret->capability));
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $capability) !== 1 || (int) $siteSecret->site_id < 1) {
            throw new RuntimeException('Site provider secret identity is invalid.');
        }

        return $this->referencePrefix().'/'.$this->provider($siteSecret->provider)
            .'/sites/'.(int) $siteSecret->site_id.'/'.$capability;
    }

    private function referencePrefix(): string
    {
        $mount = trim((string) config('monitoring.credentials.vault.kv_v2_mount'), '/');
        $prefix = trim((string) config('monitoring.credentials.vault.provider_secret_prefix'), '/');

        return $mount.'/data/'.$prefix;
    }

    /** @param array<string, scalar|null> $material */
    private function destroyMaterial(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value)) {
                $this->destroyString($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }

    private function destroyString(#[\SensitiveParameter] string &$value): void
    {
        if ($value !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }
}
