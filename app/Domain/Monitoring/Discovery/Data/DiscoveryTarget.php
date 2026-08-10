<?php

namespace App\Domain\Monitoring\Discovery\Data;

use App\Domain\Monitoring\Data\ProbeTarget;
use InvalidArgumentException;

final readonly class DiscoveryTarget
{
    public string $host;

    public function __construct(string $host, public string $source)
    {
        if (! in_array($source, ['seed', 'cidr'], true)) {
            throw new InvalidArgumentException('Discovery target source is invalid.');
        }

        $this->host = ProbeTarget::icmp($host)->host;
    }

    public function key(): string
    {
        return $this->host;
    }

    public function isAddress(): bool
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) !== false;
    }
}
