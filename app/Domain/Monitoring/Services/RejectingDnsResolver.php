<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Exceptions\EgressDenied;

final class RejectingDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        throw new EgressDenied('DNS resolution is not configured');
    }
}
