<?php

namespace App\Domain\Monitoring\Data;

use Carbon\CarbonImmutable;

final readonly class CapacityProjection
{
    public function __construct(
        public string $state,
        public ?CarbonImmutable $measuredFrom,
        public ?CarbonImmutable $measuredTo,
        public int $sampleCount,
        public ?float $p95,
        public ?float $slopePerDay,
        public float $confidence,
        public float $threshold,
        public ?CarbonImmutable $thresholdAt,
    ) {}
}
