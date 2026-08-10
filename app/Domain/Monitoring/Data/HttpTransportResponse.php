<?php

namespace App\Domain\Monitoring\Data;

final readonly class HttpTransportResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $location,
        public int $latencyMs,
        public bool $truncated,
    ) {}
}
