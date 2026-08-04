<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\IcmpTransportResult;

interface IcmpTransport
{
    public function probe(AuthorizedProbeTarget $target): IcmpTransportResult;
}
