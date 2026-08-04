<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\DnsTransportResult;

interface DnsTransport
{
    public function query(AuthorizedProbeTarget $target, string $name, string $type): DnsTransportResult;
}
