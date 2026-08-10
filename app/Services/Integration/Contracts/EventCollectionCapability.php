<?php

namespace App\Services\Integration\Contracts;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Data\ProviderEventPage;

interface EventCollectionCapability
{
    public function collectEvents(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderEventPage;
}
