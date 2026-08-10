<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\HttpTransportResponse;

interface HttpTransport
{
    public function request(AuthorizedProbeTarget $target): HttpTransportResponse;
}
