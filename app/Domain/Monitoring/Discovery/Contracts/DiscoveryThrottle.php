<?php

namespace App\Domain\Monitoring\Discovery\Contracts;

interface DiscoveryThrottle
{
    public function reset(int $packetsPerSecond): void;

    public function acquire(): void;
}
