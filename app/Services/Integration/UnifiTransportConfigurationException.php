<?php

namespace App\Services\Integration;

use App\Support\SafeOperationalData;
use RuntimeException;

final class UnifiTransportConfigurationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(SafeOperationalData::failureSummary());
    }
}
