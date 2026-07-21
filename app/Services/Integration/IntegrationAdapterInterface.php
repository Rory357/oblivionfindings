<?php

namespace App\Services\Integration;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;

interface IntegrationAdapterInterface
{
    /**
     * Test that the provided credentials can authenticate with the provider.
     */
    public function testConnection(IntegrationProviderConnection $connection): bool;

    /**
     * Discover sites/locations available via the provider API.
     * Returns an array of ['external_id' => string, 'name' => string, 'meta' => array].
     */
    public function discoverSites(IntegrationProviderConnection $connection): array;

    /**
     * Sync devices from the provider into the canonical devices table.
     * (Previously wrote to location_hardware; now targets Security & Devices registry.)
     * Returns a SyncResult with counts.
     */
    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult;

    /**
     * Pull device health/status updates.
     * Returns an array of health updates. Adapters should prefer canonical
     * identifiers (for example `device_id` or a stable provider entity id)
     * and only include `hardware_id` as a legacy fallback when required.
     */
    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array;

    /**
     * Pull events from the provider since the given datetime.
     * Returns an array of normalized event data.
     */
    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array;

    /**
     * Get the list of capabilities this adapter supports.
     */
    public function capabilities(): array;

    /**
     * Get the provider slug for this adapter.
     */
    public function provider(): string;
}
