<?php

namespace Oblivion\Collector\Exceptions;

use RuntimeException;

/**
 * A request to Oblivion failed. getCode() carries the HTTP status when there was one (0 when the
 * server couldn't be reached), so 409 "waiting for approval" and 410 "removed" can be told apart.
 */
final class CentralApiFailure extends RuntimeException
{
    public function httpStatus(): int
    {
        return $this->getCode();
    }
}
