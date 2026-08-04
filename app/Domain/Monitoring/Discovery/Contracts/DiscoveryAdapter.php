<?php

namespace App\Domain\Monitoring\Discovery\Contracts;

use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;

interface DiscoveryAdapter
{
    public function begin(DiscoveryScope $scope): void;

    /** @return iterable<DiscoveryTarget> */
    public function targets(DiscoveryScope $scope): iterable;

    public function discover(DiscoveryScope $scope, DiscoveryTarget $target): DiscoveryProbeResult;
}
