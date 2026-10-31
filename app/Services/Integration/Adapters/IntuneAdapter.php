<?php

namespace App\Services\Integration\Adapters;

use App\Domain\SecurityDevices\Endpoints\Services\IntuneReadClient;
use App\Domain\SecurityDevices\Endpoints\Services\IntuneVisibilitySync;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use App\Services\Integration\SyncResult;
use App\Support\SafeOperationalData;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Intune read-only Graph adapter for Windows computer discovery and posture sync.
 *
 * Official Microsoft documentation:
 * - https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0
 * - https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
final class IntuneAdapter implements ConnectionHealthCapability, DeviceSyncCapability, IntegrationAdapterInterface, InventoryDiscoveryCapability
{
    public const PROVIDER_SLUG = 'intune';

    public function __construct(
        private readonly IntuneReadClient $client,
        private readonly IntuneVisibilitySync $visibilitySync,
        private readonly IntegrationSecretMaterialService $secrets,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER_SLUG;
    }

    public function capabilities(): array
    {
        return [
            ConnectionHealthCapability::class,
            InventoryDiscoveryCapability::class,
            DeviceSyncCapability::class,
        ];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        try {
            if (! $this->hasSecretMaterial($connection)) {
                return false;
            }

            return $this->client->testConnection($connection);
        } catch (\Throwable $error) {
            Log::info('Intune testConnection failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => SafeOperationalData::failureCategory($error),
            ]));

            return false;
        }
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        if (! $this->hasSecretMaterial($connection)) {
            throw new \RuntimeException('Intune credentials are not configured.');
        }

        $items = [];
        foreach ($this->client->devicesForConnection($connection) as $snapshot) {
            $operatingSystem = strtolower(trim((string) ($snapshot['operatingSystem'] ?? '')));
            if (! str_contains($operatingSystem, 'windows')) {
                continue;
            }

            $id = strtolower(trim((string) ($snapshot['id'] ?? '')));
            if ($id === '') {
                continue;
            }

            $items[] = [
                'external_id' => $id,
                'name' => trim((string) ($snapshot['deviceName'] ?? '')) ?: 'Windows computer '.substr($id, 0, 8),
                'meta' => $snapshot,
            ];
        }

        return $items;
    }

    public function syncDevices(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
    ): SyncResult {
        $result = new SyncResult;
        try {
            if (! $this->hasSecretMaterial($providerConnection)) {
                $result->error = SafeOperationalData::failureSummary();

                return $result;
            }

            $summary = $this->visibilitySync->sync();
            $result->updated = (int) ($summary['updated'] ?? 0);
            $result->errored = (int) ($summary['not_found'] ?? 0);
            $result->processed = $result->updated + $result->errored;
        } catch (\Throwable $error) {
            Log::warning('Intune linked posture sync failed', SafeOperationalData::logContext([
                'site_id' => $siteConfig->site_id,
                'provider_connection_id' => $providerConnection->id,
                'error_category' => SafeOperationalData::failureCategory($error),
            ]));
            $result->error = SafeOperationalData::failureSummary();
        }

        return $result;
    }

    public function pullEvents(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?\DateTimeInterface $since = null,
    ): array {
        return [];
    }

    private function hasSecretMaterial(IntegrationProviderConnection $connection): bool
    {
        $secret = $this->secrets->application(
            $connection,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'client_secret',
        );
        $present = is_string($secret) && trim($secret) !== '';

        if (is_string($secret)) {
            try {
                sodium_memzero($secret);
            } catch (\Throwable) {
            }
        }

        return $present;
    }
}
