<?php

namespace App\Domain\Monitoring\Data;

final readonly class TcpTransportResult
{
    public function __construct(
        public bool $connected,
        public ?int $latencyMs,
        public string $reasonCode,
    ) {}
}
