<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\TlsTransportResult;

interface TlsTransport
{
    public function probe(AuthorizedProbeTarget $target): TlsTransportResult;
}
