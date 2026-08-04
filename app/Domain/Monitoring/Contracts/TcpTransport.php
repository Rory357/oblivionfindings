<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\TcpTransportResult;

interface TcpTransport
{
    public function probe(AuthorizedProbeTarget $target): TcpTransportResult;
}
