<?php

namespace App\Services\Integration\Adapters;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\SyncResult;

/**
 * Queclink integration adapter.
 *
 * No verified public Queclink cloud contract is currently enabled. Native TCP
 * intake, canonical tracking, and governed Device Management are implemented
 * through their own typed paths and must not be represented as cloud API
 * capabilities here.
 */
class QueclinkAdapter implements IntegrationAdapterInterface
{
    public const PROVIDER_SLUG = 'queclink';

    public function provider(): string
    {
        return self::PROVIDER_SLUG;
    }

    public function capabilities(): array
    {
        return [];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        return false;
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        return new SyncResult(
            processed: 0,
            created: 0,
            updated: 0,
            errored: 0,
            error: 'Queclink cloud device sync is unavailable because no verified provider contract is enabled. Native TCP monitoring and governed Device Management remain separate.',
        );
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array
    {
        return [];
    }
}
