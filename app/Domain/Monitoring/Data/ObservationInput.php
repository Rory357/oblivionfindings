<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\MonitorObservation;
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

    public static function fromObservation(MonitorObservation $observation): self
    {
        return new self(
            sourceKey: (string) $observation->source_key,
            state: $observation->state,
            observedAt: CarbonImmutable::instance($observation->observed_at),
            value: $observation->value === null ? null : (float) $observation->value,
            unit: $observation->unit,
            latencyMs: $observation->latency_ms,
            message: $observation->message,
            metrics: is_array($observation->metrics) ? $observation->metrics : [],
        );
    }
}
