<?php

namespace App\Services\Integration\Contracts;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\SyncResult;

interface DeviceSyncCapability
{
    public function syncDevices(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
    ): SyncResult;
}
