<?php

namespace App\Domain\Monitoring\Data;

final readonly class ProbeScope
{
    /**
     * @param  list<string>  $approvedCidrs
     * @param  list<int>  $allowedPorts
     */
    public function __construct(
        public int $siteId,
        public int $deviceId,
        public array $approvedCidrs,
        public array $allowedPorts,
        public ?int $connectTimeoutSeconds = null,
        public ?int $responseTimeoutSeconds = null,
        public ?int $maxResponseBytes = null,
    ) {}
}
