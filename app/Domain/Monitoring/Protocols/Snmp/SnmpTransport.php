<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;

interface SnmpTransport
{
    public function poll(
        AuthorizedProbeTarget $target,
        CredentialLease $lease,
        SnmpQuery $query,
    ): SnmpTransportResult;
}
