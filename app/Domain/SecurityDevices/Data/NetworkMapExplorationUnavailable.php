<?php

namespace App\Domain\SecurityDevices\Data;

use RuntimeException;

final class NetworkMapExplorationUnavailable extends RuntimeException
{
    public function __construct(public readonly int $siteId)
    {
        parent::__construct('Current connections are unavailable for this view.');
    }
}
