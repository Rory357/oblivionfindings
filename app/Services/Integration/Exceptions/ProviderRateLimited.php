<?php

namespace App\Services\Integration\Exceptions;

use RuntimeException;

final class ProviderRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Provider request was rate limited.');
    }
}
