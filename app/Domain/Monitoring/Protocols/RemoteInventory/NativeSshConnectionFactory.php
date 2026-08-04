<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use RuntimeException;

final class NativeSshConnectionFactory implements SshConnectionFactory
{
    public function connect(AuthorizedProbeTarget $target, string $address): SshConnection
    {
        if ($target->scheme !== 'ssh' || $target->port < 1 || $target->port > 65535
            || filter_var($address, FILTER_VALIDATE_IP) === false
            || ! in_array($address, $target->addresses, true)) {
            throw new RuntimeException('SSH target is invalid.');
        }

        return new PhpseclibSshConnection(
            $address,
            $target->port,
            min($target->connectTimeoutSeconds, 15),
        );
    }
}
