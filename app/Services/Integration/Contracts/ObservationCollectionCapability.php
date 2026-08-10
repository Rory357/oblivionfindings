<?php

namespace App\Services\Integration\Contracts;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Data\ProviderObservationPage;

interface ObservationCollectionCapability
{
    public function collectObservations(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderObservationPage;
}
