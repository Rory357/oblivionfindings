<?php

namespace App\Domain\Monitoring\Topology\Exceptions;

use RuntimeException;

final class ProviderTopologyDeferred extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Provider topology collection is incomplete.');
    }
}
