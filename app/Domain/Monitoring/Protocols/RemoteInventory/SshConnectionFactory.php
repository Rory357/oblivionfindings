<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;

interface SshConnectionFactory
{
    public function connect(AuthorizedProbeTarget $target, string $address): SshConnection;
}
