<?php

namespace App\Services\Integration\Contracts;

use App\Models\Integration\IntegrationProviderConnection;

interface ConnectionHealthCapability
{
    public function testConnection(IntegrationProviderConnection $connection): bool;
}
