<?php

namespace App\Domain\Monitoring\Data;

use Carbon\CarbonImmutable;

final readonly class TimeSeriesPoint
{
    /**
     * @param  array<string, string|int|bool>  $dimensions
     * @param  array{p50?: float, p95?: float, min?: float, max?: float, count?: int}  $statistics
     */
    public function __construct(
        public string $externalKey,
        public int $seriesId,
        public int $siteId,
        public int $deviceId,
        public ?int $monitorId,
        public string $metric,
        public float $value,
        public string $unit,
        public array $dimensions,
        public string $tier,
        public CarbonImmutable $observedAt,
        public string $idempotencyKey,
        public array $statistics = [],
    ) {}
}
