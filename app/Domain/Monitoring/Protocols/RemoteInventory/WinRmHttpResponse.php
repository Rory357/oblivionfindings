<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

final readonly class WinRmHttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public int $latencyMs,
        public bool $truncated,
    ) {}
}
