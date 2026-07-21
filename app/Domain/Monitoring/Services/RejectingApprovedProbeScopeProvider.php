<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Exceptions\EgressDenied;

final class RejectingApprovedProbeScopeProvider implements ApprovedProbeScopeProvider
{
    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope
    {
        throw new EgressDenied('approved probe scope is not configured');
    }
}
