<?php

namespace App\Domain\Monitoring\Data;

final readonly class IcmpTransportResult
{
    public function __construct(
        public bool $reachable,
        public ?int $latencyMs,
        public float $packetLossPercent,
        public string $reasonCode,
    ) {}
}
