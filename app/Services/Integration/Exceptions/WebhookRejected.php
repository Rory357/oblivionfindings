<?php

namespace App\Services\Integration\Exceptions;

use RuntimeException;

final class WebhookRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus = 401,
    ) {
        parent::__construct('Provider webhook was rejected.');
    }
}
