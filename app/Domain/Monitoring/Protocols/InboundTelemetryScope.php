<?php

namespace App\Domain\Monitoring\Protocols;

final readonly class InboundTelemetryScope
{
    public function __construct(
        public int $siteId,
        public int $scopeId,
        public ?int $candidateDeviceId,
    ) {}
}
