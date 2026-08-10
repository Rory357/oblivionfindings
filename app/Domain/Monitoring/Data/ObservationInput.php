<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;

final readonly class ObservationInput
{
    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public function __construct(
        public string $sourceKey,
        public MonitorState $state,
        public CarbonImmutable $observedAt,
        public int|float|null $value = null,
        public ?string $unit = null,
        public ?int $latencyMs = null,
        public ?string $message = null,
        public array $metrics = [],
    ) {}
}
