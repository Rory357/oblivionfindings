<?php

namespace App\Services\Integration\Contracts;

use App\Models\Integration\IntegrationProviderConnection;

interface InventoryDiscoveryCapability
{
    /** @return array<int, array<string, mixed>> */
    public function discoverSites(IntegrationProviderConnection $connection): array;
}
