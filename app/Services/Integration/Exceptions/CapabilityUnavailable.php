<?php

namespace App\Services\Integration\Exceptions;

use RuntimeException;

final class CapabilityUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Provider capability is unavailable.');
    }
}
