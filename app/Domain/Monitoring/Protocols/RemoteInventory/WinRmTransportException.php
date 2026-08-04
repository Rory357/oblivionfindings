<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use RuntimeException;

final class WinRmTransportException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
