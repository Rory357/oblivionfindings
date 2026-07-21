<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\ProbeScope;

interface ApprovedProbeScopeProvider
{
    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope;
}
