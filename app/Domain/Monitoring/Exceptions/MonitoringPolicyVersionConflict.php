<?php

namespace App\Domain\Monitoring\Exceptions;

use RuntimeException;

final class MonitoringPolicyVersionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The monitoring policy changed after it was opened. Reload it before continuing.');
    }
}
