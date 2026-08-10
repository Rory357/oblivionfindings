<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\ProbeScope;

interface ProbeScopeResolver
{
    public function resolve(int $siteId, int $deviceId): ProbeScope;
}
