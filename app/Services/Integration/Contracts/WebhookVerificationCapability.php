<?php

namespace App\Services\Integration\Contracts;

use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\Data\ProviderWebhookRequest;
use App\Services\Integration\Data\VerifiedProviderEventBatch;

interface WebhookVerificationCapability
{
    public function verifyWebhook(
        IntegrationProviderConnection $connection,
        ProviderWebhookRequest $request,
    ): VerifiedProviderEventBatch;
}
